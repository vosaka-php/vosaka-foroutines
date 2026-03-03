<?php

declare(strict_types=1);

namespace vosaka\foroutines\channel;

use Exception;
use ReflectionClass;
use ReflectionProperty;
use ReflectionMethod;
use JsonSerializable;
use Serializable;

/**
 * Handles serialization/deserialization of channel data.
 *
 * Extracted from Channel to keep each class focused on a single
 * responsibility.  Supports multiple serialization backends:
 *
 *   - serialize/unserialize (PHP native — default)
 *   - JSON
 *   - msgpack  (requires ext-msgpack)
 *   - igbinary (requires ext-igbinary)
 *
 * Object-type preservation is supported for JSON serialization via
 * reflection-based objectToArray / arrayToObject round-tripping.
 *
 * @internal Used by Channel and ChannelFileTransport.  Not part of the
 *           public API — call Channel methods instead.
 */
final class ChannelSerializer
{
    public const SERIALIZER_SERIALIZE = "serialize";
    public const SERIALIZER_JSON = "json";
    public const SERIALIZER_MSGPACK = "msgpack";
    public const SERIALIZER_IGBINARY = "igbinary";

    private string $serializer;
    private bool $preserveObjectTypes;

    public function __construct(
        string $serializer = self::SERIALIZER_SERIALIZE,
        bool $preserveObjectTypes = true,
    ) {
        $this->serializer = $serializer;
        $this->preserveObjectTypes = $preserveObjectTypes;
    }

    // ─── Getters ─────────────────────────────────────────────────────

    public function getSerializer(): string
    {
        return $this->serializer;
    }

    public function getPreserveObjectTypes(): bool
    {
        return $this->preserveObjectTypes;
    }

    // ─── Core serialize / unserialize ────────────────────────────────

    /**
     * Serialize data based on configured serializer.
     */
    public function serializeData(mixed $data): string
    {
        try {
            switch ($this->serializer) {
                case self::SERIALIZER_JSON:
                    if (is_object($data) && $this->preserveObjectTypes) {
                        $serialized = [
                            "__class__" => get_class($data),
                            "__data__" => $this->objectToArray($data),
                        ];
                        return json_encode($serialized, JSON_THROW_ON_ERROR);
                    }
                    return json_encode($data, JSON_THROW_ON_ERROR);

                case self::SERIALIZER_MSGPACK:
                    if (function_exists("msgpack_pack")) {
                        return \msgpack_pack($data);
                    }
                    return serialize($data);

                case self::SERIALIZER_IGBINARY:
                    if (function_exists("igbinary_serialize")) {
                        return \igbinary_serialize($data);
                    }
                    return serialize($data);

                case self::SERIALIZER_SERIALIZE:
                default:
                    return serialize($data);
            }
        } catch (Exception $e) {
            throw new Exception(
                "Failed to serialize data: " . $e->getMessage(),
            );
        }
    }

    /**
     * Unserialize data based on configured serializer.
     */
    public function unserializeData(string $data): mixed
    {
        try {
            switch ($this->serializer) {
                case self::SERIALIZER_JSON:
                    $decoded = json_decode(
                        $data,
                        true,
                        512,
                        JSON_THROW_ON_ERROR,
                    );

                    if (
                        $this->preserveObjectTypes &&
                        is_array($decoded) &&
                        isset($decoded["__class__"]) &&
                        isset($decoded["__data__"])
                    ) {
                        return $this->arrayToObject(
                            $decoded["__class__"],
                            $decoded["__data__"],
                        );
                    }

                    return $decoded;

                case self::SERIALIZER_MSGPACK:
                    if (function_exists("msgpack_unpack")) {
                        return \msgpack_unpack($data);
                    }
                    return unserialize($data);

                case self::SERIALIZER_IGBINARY:
                    if (function_exists("igbinary_unserialize")) {
                        return \igbinary_unserialize($data);
                    }
                    return unserialize($data);

                case self::SERIALIZER_SERIALIZE:
                default:
                    return unserialize($data);
            }
        } catch (Exception $e) {
            throw new Exception(
                "Failed to unserialize data: " . $e->getMessage(),
            );
        }
    }

    // ─── Object ↔ Array conversion (JSON round-trip) ─────────────────

    /**
     * Convert object to array for JSON serialization.
     */
    public function objectToArray(object $object): array
    {
        if ($object instanceof JsonSerializable) {
            return $object->jsonSerialize();
        }

        $reflection = new ReflectionClass($object);
        $data = [];

        // Get public properties
        foreach (
            $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
            as $property
        ) {
            $data[$property->getName()] = $property->getValue($object);
        }

        // Get properties via getter methods
        foreach (
            $reflection->getMethods(ReflectionMethod::IS_PUBLIC)
            as $method
        ) {
            $methodName = $method->getName();
            if (
                strpos($methodName, "get") === 0 &&
                $method->getNumberOfParameters() === 0
            ) {
                $propertyName = lcfirst(substr($methodName, 3));
                if (!isset($data[$propertyName])) {
                    try {
                        $data[$propertyName] = $method->invoke($object);
                    } catch (Exception) {
                        // Skip if getter throws exception
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Convert array back to object for JSON deserialization.
     */
    public function arrayToObject(string $className, array $data): object
    {
        if (!class_exists($className)) {
            throw new Exception("Class {$className} does not exist");
        }

        $reflection = new ReflectionClass($className);

        try {
            $object = $reflection->newInstanceWithoutConstructor();

            foreach (
                $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
                as $property
            ) {
                $propertyName = $property->getName();
                if (array_key_exists($propertyName, $data)) {
                    $property->setValue($object, $data[$propertyName]);
                }
            }

            foreach ($data as $key => $value) {
                $setterName = "set" . ucfirst($key);
                if ($reflection->hasMethod($setterName)) {
                    $method = $reflection->getMethod($setterName);
                    if (
                        $method->isPublic() &&
                        $method->getNumberOfParameters() >= 1
                    ) {
                        try {
                            $method->invoke($object, $value);
                        } catch (Exception) {
                            // Skip if setter throws exception
                        }
                    }
                }
            }

            return $object;
        } catch (Exception) {
            try {
                $constructor = $reflection->getConstructor();
                if (
                    $constructor &&
                    $constructor->getNumberOfParameters() === 0
                ) {
                    $object = $reflection->newInstance();
                } else {
                    $object = $this->createObjectWithConstructor(
                        $reflection,
                        $data,
                    );
                }

                foreach (
                    $reflection->getProperties(ReflectionProperty::IS_PUBLIC)
                    as $property
                ) {
                    $propertyName = $property->getName();
                    if (array_key_exists($propertyName, $data)) {
                        $property->setValue($object, $data[$propertyName]);
                    }
                }

                return $object;
            } catch (Exception $e2) {
                throw new Exception(
                    "Failed to recreate object of class {$className}: " .
                        $e2->getMessage(),
                );
            }
        }
    }

    /**
     * Create object with constructor parameters.
     */
    private function createObjectWithConstructor(
        ReflectionClass $reflection,
        array $data,
    ): object {
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return $reflection->newInstance();
        }

        $parameters = $constructor->getParameters();
        $args = [];

        foreach ($parameters as $param) {
            $paramName = $param->getName();

            if (array_key_exists($paramName, $data)) {
                $args[] = $data[$paramName];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $args[] = null;
            } else {
                $variations = [
                    $paramName,
                    lcfirst($paramName),
                    ucfirst($paramName),
                    strtolower($paramName),
                ];

                $found = false;
                foreach ($variations as $variation) {
                    if (array_key_exists($variation, $data)) {
                        $args[] = $data[$variation];
                        $found = true;
                        break;
                    }
                }

                if (!$found) {
                    throw new Exception(
                        "Cannot find value for required parameter: {$paramName}",
                    );
                }
            }
        }

        return $reflection->newInstanceArgs($args);
    }

    // ─── Utility / introspection ─────────────────────────────────────

    /**
     * Check if the given data can be serialized with the current backend.
     */
    public function canSerialize(mixed $data): bool
    {
        try {
            $this->serializeData($data);
            return true;
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Return detailed serialization info for the given data.
     */
    public function getSerializationInfo(mixed $data): array
    {
        $info = [
            "type" => gettype($data),
            "serializable" => $this->canSerialize($data),
            "size_bytes" => 0,
            "serializer" => $this->serializer,
            "preserve_object_types" => $this->preserveObjectTypes,
        ];

        if (is_object($data)) {
            $info["class"] = get_class($data);
            $info["implements_json_serializable"] =
                $data instanceof JsonSerializable;
            $info["implements_serializable"] = $data instanceof Serializable;

            $reflection = new ReflectionClass($data);
            $info["has_sleep_method"] = $reflection->hasMethod("__sleep");
            $info["has_wakeup_method"] = $reflection->hasMethod("__wakeup");
            $info["has_serialize_method"] = $reflection->hasMethod(
                "__serialize",
            );
            $info["has_unserialize_method"] = $reflection->hasMethod(
                "__unserialize",
            );
        }

        if ($info["serializable"]) {
            try {
                $serialized = $this->serializeData($data);
                $info["size_bytes"] = strlen($serialized);
            } catch (Exception) {
                $info["size_bytes"] = 0;
            }
        }

        return $info;
    }

    /**
     * Test whether $data survives a serialize → unserialize round-trip.
     */
    public function testData(mixed $data): array
    {
        $result = [
            "compatible" => false,
            "info" => $this->getSerializationInfo($data),
            "errors" => [],
            "warnings" => [],
        ];

        try {
            $serialized = $this->serializeData($data);
            $unserialized = $this->unserializeData($serialized);

            $result["compatible"] = true;
            $result["serialized_size"] = strlen($serialized);

            if (is_object($data) && is_object($unserialized)) {
                if (get_class($data) !== get_class($unserialized)) {
                    $result["warnings"][] =
                        "Object class changed during serialization";
                }
            }

            if (strlen($serialized) > 1024 * 1024) {
                $result["warnings"][] =
                    "Large data size may impact performance";
            }
        } catch (Exception $e) {
            $result["errors"][] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Suggest the best serializer for the given data.
     */
    public static function getRecommendedSerializer(mixed $data): string
    {
        if (is_object($data)) {
            if (function_exists("igbinary_serialize")) {
                return self::SERIALIZER_IGBINARY;
            }
            return self::SERIALIZER_SERIALIZE;
        }

        if (is_array($data) || is_scalar($data)) {
            if (function_exists("msgpack_pack")) {
                return self::SERIALIZER_MSGPACK;
            }
            return self::SERIALIZER_JSON;
        }

        return self::SERIALIZER_SERIALIZE;
    }
}

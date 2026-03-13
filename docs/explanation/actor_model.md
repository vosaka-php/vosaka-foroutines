# Explanation: Actor Model

The Actor Model is a design pattern for concurrency that avoids shared state. Instead of using locks (Mutex) to protect data, each "Actor" owns its own state and communicates via messages.

## Key Concepts

1.  **Mailbox**: Every Actor has a `Channel` that acts as its mailbox.
2.  **Message Passing**: You don't call methods on an Actor; you send it a `Message`.
3.  **No Shared State**: Since the Actor is the only one who can touch its internal variables, you eliminate race conditions by design.

## Why use Actors in Foroutines?
While `Mutex` is available, it can be complex to manage in a multi-process environment. Actors provide a higher-level abstraction where the "lock" is implicit in the sequential processing of the mailbox. 

In Foroutines, Actors are integrated with the `Channel` system, allowing them to communicate seamlessly across process boundaries if the `IO` dispatcher is used.

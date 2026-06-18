# Result Callbacks

Result callbacks let the destination report command outcomes back to the source. P.46 defines public contracts for callback senders and receivers while leaving concrete host implementations in applications.

## Sender Contract

`ResultCallbackSenderContract` accepts a message, a generic command result, and optional transport settings. Destination apps can bind this contract to their callback sender.

## Receiver Contract

`ResultCallbackReceiverContract` accepts a signed callback envelope and headers. Source apps can bind this contract to their callback receiver.

## Result Contract

`IncomingCommandResultContract` describes the stable fields a callback needs:

- success or failure
- retryability
- error class and message
- result payload
- metadata

## Boundary

Callback envelopes, signing, verification, and lifecycle transitions are generic package candidates. Deciding what a result means for a host business process remains the host application's job.

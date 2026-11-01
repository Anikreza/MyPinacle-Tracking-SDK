# Changelog

## 1.2.0 - 2026-11-01
Confirmed-order purchase tracking support. Backward compatible: existing calls behave as in 1.1.0.
- `event_time` for Meta CAPI (`payload['event_time']`) and TikTok (`$options['event_time']`); unix seconds or `DateTimeInterface`, default now.
- GA4 Measurement Protocol: `timestamp_micros` (`$extra['timestamp_micros']` or `$extra['event_time']`) and `session_id` (`$extra['ga_session_id']`).
- `Ga4MeasurementProtocol::refund()` / `refundRequest()` (full or partial with `refund_ref`).
- Request builders that return an `OutgoingRequest` without dispatching or claiming the send-once ledger, for an app outbox:
  `MetaConversionsApi::request()`, `TikTokEventsApi::request()`, `Ga4MeasurementProtocol::purchaseRequest()` / `refundRequest()`,
  `ServerEvents::purchaseRequests()` / `refundRequest()`.
- `ServerEvents::orderPlaced()`: Meta custom event `OrderPlaced` + TikTok `PlaceAnOrder`, once per transaction id.
- `Support\EventTime` helper.

## 1.1.0
- Forward `properties.content_type` to Meta CAPI (product_group ViewContent); license MIT.

## 1.0.0
- Shared Meta CAPI / Graph, TikTok Events API, GA4 MP, hashing, country, event ids + send-once ledger.

-- ORK3 — ork_api_tally: daily API call counts per client, per endpoint
-- =============================================================================
-- ork_signin_tally answers "who logged in today". It fires only in
-- Authorization::CreateSession(), so a third-party app that authenticates once
-- and then calls the service for a month is invisible after day one — which is
-- exactly how a well-behaved API client behaves. This is the same idea for
-- CALLS rather than logins.
--
-- Written by JsonServer::call_endpoint(), the single chokepoint every JSON
-- service call passes through.
--
-- SHAPE: (day, client, endpoint) -> count + summed duration.
--
-- ms_total exists so this can answer the optimisation question, not just the
-- popularity one. Calls alone rank by chattiness; calls x duration ranks by
-- where the server time actually goes, which is the number worth acting on. A
-- cheap endpoint called 50,000 times and an expensive one called 50 can look
-- identical in a bare count and could hardly be less alike. Average per call
-- is ms_total/calls; total cost is ms_total.
--
-- ANONYMOUS BY DESIGN, matching ork_signin_tally's stated principle: no
-- mundane_id, no IP, no token. This records that a CLASS OF CLIENT called an
-- endpoint N times, never who it acted for. Attributing a specific call to a
-- specific player is the audit log's job (ork_danger_audit), not a usage
-- counter's.
--
-- CARDINALITY is bounded by design: `client` is the collapsed bucket from
-- ork_session_client_label() (roughly a dozen values -- "mORK on iOS", "jsork",
-- "BlackspireWaiver/1.0..."), and `endpoint` is only recorded for calls that
-- actually dispatched to a real class+method, so a scanner probing
-- ?call=Foo/bar cannot mint rows. Realistically a few hundred rows a day.
--
-- Column is `endpoint`, NOT `call` -- CALL is a reserved word in MySQL and
-- would need backticking at every use site.
--
-- Additive and re-runnable. MariaDB client, not mysql. No destructive ops.

CREATE TABLE IF NOT EXISTS `ork_api_tally` (
  `day`      date              NOT NULL,
  `client`   varchar(40)       NOT NULL,
  `endpoint` varchar(64)       NOT NULL,
  `calls`    int(10) unsigned  NOT NULL DEFAULT 0,
  `ms_total` bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`day`, `client`, `endpoint`),
  KEY `ix_day_calls` (`day`, `calls`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

=== WP Wren Dashboards ===
Contributors: manudrago
Tags: analytics, dashboard, ai, charts, text-to-sql
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ask anything about your WordPress data in plain language and get instant, saveable dashboards, powered by Wren AI.

== Description ==

WP Wren Dashboards puts a question box on any page. Someone types "how many posts did we
publish each month this year?", and gets a chart, a table and a CSV — then saves it as a panel
on a dashboard that anybody can embed with a shortcode.

The plugin sends your database *structure* (never its contents) to a Wren AI instance you
control, asks Wren to turn the question into SQL, validates that SQL against a strict
read-only guard, runs it, and asks Wren for a chart specification which it renders as inline
SVG — no external chart library, no CDN.

**Shortcodes**

* `[wren_ai_dashboard]` — the ask form.
* `[wren_dashboard id="12"]` — a saved dashboard.

**Security**

The model is treated as an untrusted source of SQL. Every statement must be a single
SELECT/WITH, may only touch tables you explicitly shared, may not reference blocked columns or
system schemas, and always carries a LIMIT. Blocked columns are stripped from the model and
masked in results. Define WWD_DB_USER / WWD_DB_PASSWORD in wp-config.php to run every
analytics query on a MySQL user that only has SELECT rights.

Asking requires a capability (default: edit_posts); public access is opt-in. Every question
and statement is logged.

== Installation ==

1. Run a Wren AI service reachable from your WordPress server (see docs/wren-ai-setup.md).
2. Upload the plugin to /wp-content/plugins/ and activate it.
3. Wren AI → Settings: set the endpoint and test the connection.
4. Wren AI → Data & schema: pick the tables to share, add business context, deploy the schema.
5. Put `[wren_ai_dashboard]` on a page.

== Frequently Asked Questions ==

= Which Wren AI version does this need? =

The REST API of wren-ai-service: Wren AI self-hosted "GenBI Classic" (the legacy/v1 branch and
its Docker images) or Wren AI Cloud. The current agent-driven CLI on main does not expose that
HTTP service.

= What leaves my site? =

The question, the schema (table and column names, types, relationships, your descriptions) and
a sample of at most 200 result rows used to design the chart. The sample can be disabled with
the wwd_chart_sample_rows filter.

= Can visitors ask questions? =

Only if you enable public access explicitly. They will be able to run aggregate queries over
the shared tables, so share only tables that are safe to expose.

== Changelog ==

= 1.0.0 =
* First release: ask form, chart and table rendering, saved dashboards, schema deployment,
  SQL guard, query log, read-only connection support.

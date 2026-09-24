=== WP Wren Dashboards ===
Contributors: manudrago
Tags: analytics, dashboard, ai, charts, text-to-sql
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ask anything about your WordPress data in plain language and get instant, saveable dashboards. One API key, no server.

== Description ==

WP Wren Dashboards puts a question box on any page. Someone types "how many posts did we
publish each month this year?", and gets a chart, a table and a CSV — then saves it as a panel
on a dashboard that anybody can embed with a shortcode.

The plugin sends your database *structure* (never its contents) to a language model of your
choosing, asks it to turn the question into SQL, validates that SQL against a strict read-only
guard, runs it on your database, and asks for a chart specification which it renders as inline
SVG — no external chart library, no CDN.

Nothing to install anywhere: an API key is the whole setup, and Google AI Studio and Groq give
one away. A Wren AI service can be used instead of the model, for sites that already run one.

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

1. Upload the plugin to /wp-content/plugins/ and activate it.
2. Wren AI → Settings: paste an API key (get a free one at aistudio.google.com/apikey) and
   press "Test connection".
3. Wren AI → Data & schema: pick the tables to share and add business context. Nothing to
   deploy — the schema travels with every question.
4. Put `[wren_ai_dashboard]` on a page.

Prefer to run Wren AI? Pick that engine in Settings; deploy/README.md installs one on any
Ubuntu/Debian machine with a single command.

== Frequently Asked Questions ==

= Which model does this need? =

Any of: Google AI Studio (free tier, the default), Groq (free tier), OpenAI, or anything that
speaks the OpenAI chat-completions API — Ollama and LM Studio included, so the model can run
on your own hardware.

= Which Wren AI version does this need, if I use that engine? =

The REST API of wren-ai-service: Wren AI self-hosted "GenBI Classic" (the legacy/v1 branch and
its Docker images) or Wren AI Cloud. The current agent-driven CLI on main does not expose that
HTTP service.

= What leaves my site? =

The question, the schema (table and column names, types, relationships, your descriptions) and
a sample of the result rows used to design the chart — 30 by default, adjustable with the
wwd_chart_sample_rows filter. Never the rest of your data: the SQL runs here.

= Can visitors ask questions? =

Only if you enable public access explicitly. They will be able to run aggregate queries over
the shared tables, so share only tables that are safe to expose.

== Changelog ==

= 1.3.1 =
* Survive a provider's JSON mode rejecting its own model's answer ("Failed to generate JSON"):
  the answer inside the rejection is read when it is usable, and otherwise the question is
  asked again without the JSON constraint and parsed leniently.
* Reasoning models are usable: <think> narration is stripped before parsing, and the token
  budget leaves room for it.

= 1.3.0 =
* Settings can ask the provider which models the key can actually use, and offer them as
  buttons to pick from. Providers retire models on their own schedule - both defaults shipped
  in 1.2.0 went stale within a week - so asking beats any list written into a release.
* An unknown-model error now points at that button.

= 1.2.2 =
* A busy provider no longer costs the question: HTTP 503, 429, 5xx and transport failures are
  waited out with a backoff (2s to 30s, about two and a half minutes in total) while the card
  says the model is busy. A refused key, an unknown model or a malformed answer still fail
  straight away, since waiting would not help.

= 1.2.1 =
* Google retired gemini-2.0-flash: the default is now gemini-3.6-flash. Any provider's model
  can be overridden in Settings, and an unknown-model error now says where to put the
  replacement the provider names.

= 1.2.0 =
* New default engine: the plugin asks a language model directly, so there is no service to
  install, host or keep running, and no schema deployment step. A WordPress schema fits in a
  prompt.
* Providers: Google AI Studio, Groq, OpenAI, and any OpenAI-compatible endpoint (Ollama,
  LM Studio, OpenRouter).
* Wren AI remains available as an engine; sites already using it keep using it after the
  upgrade.

= 1.1.0 =
* Pairing: generate a code in Settings, run the printed command on any Ubuntu/Debian machine,
  and the endpoint and API key arrive by themselves. A server behind a Cloudflare quick tunnel
  keeps reporting its address, so a restarted tunnel no longer breaks the connection.
* The installer in deploy/ is no longer Oracle-specific and can use a free hosted model
  (Google AI Studio, Groq) instead of a local one, which brings the requirement down to a
  2 GB machine.

= 1.0.0 =
* First release: ask form, chart and table rendering, saved dashboards, schema deployment,
  SQL guard, query log, read-only connection support.

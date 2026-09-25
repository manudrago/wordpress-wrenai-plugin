=== WP Wren Dashboards ===
Contributors: manudrago
Tags: analytics, dashboard, ai, charts, text-to-sql
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.8.0
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

Everything happens in wp-admin under **Wren AI**: Ask, Dashboards, Data & schema, Settings,
Query log. No page to create, no shortcode to paste, and the WordPress login is the only door.

**Shortcodes** (optional, for showing a dashboard to people without wp-admin access)

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
4. Wren AI → Ask, and ask something. Optionally put `[wren_ai_dashboard]` on a page to reach
   people who do not have wp-admin access.

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

= 1.8.0 =
* The plugin lives in wp-admin now: "Wren AI" opens the ask form, and a Dashboards screen shows
  the saved panels. Nothing has to be published to a page to use it, and the WordPress login is
  the only way in. The shortcodes stay, for showing a dashboard to people without wp-admin
  access.
* Settings moved to its own submenu, since it is visited twice: at the start, and when
  something breaks.

= 1.7.0 =
* A site without a licence keeps two saved panels. Panels saved before the limit existed are
  never touched: only new ones are refused, and removing one makes room again. Define WWD_PRO
  or filter wwd_is_licensed to lift it.

= 1.6.1 =
* Questions about time now produce one sortable period column instead of a year column and a
  month column, which a chart cannot put in order across a year boundary, and rows come back
  oldest first.

= 1.6.0 =
* The reader picks the chart. The model's choice is still the default, but every answer now
  offers the shapes its data can honestly take - columns, bars, line, area, pie - and switching
  is one click. Shapes that would mislead are not offered: no line over categories with no
  order, no pie of negative numbers or of more than ten slices.
* Saving a panel saves the shape you are looking at, and the dashboard opens it that way.

= 1.5.0 =
* A time axis now runs forwards whatever order the query returned, so "ORDER BY month DESC"
  no longer draws the year backwards.
* Line charts print their values when there are ten points or fewer.
* KPI captions read as words rather than column names: orders_this_year becomes
  "Orders this year".
* Charts follow the theme: define --wwd-series-1 … --wwd-series-10 to draw them in your own
  brand colours.

= 1.4.1 =
* Newer OpenAI models renamed max_tokens to max_completion_tokens and refuse a temperature
  they did not choose. The request now adapts to whatever the provider objects to, instead of
  failing with a parameter name nobody outside the provider can be expected to know.
* The model picker leaves out realtime, audio, image and video models, which cannot answer a
  question at all.

= 1.4.0 =
* Charts with many categories, or long ones, are drawn horizontally: labels read normally
  instead of being rotated and thinned until nobody can tell which bar is which. The long tail
  is gathered into one row, with a note saying how many and pointing at the table.
* Bar values are printed on the chart, so reading it does not require hovering.
* Anything ordered by time is never reordered.

= 1.3.2 =
* A model whose context window cannot hold the schema plus the room reserved for an answer is
  asked again with less room reserved, and if it still does not fit the error says so: share
  fewer tables, or pick a model with a larger window.

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

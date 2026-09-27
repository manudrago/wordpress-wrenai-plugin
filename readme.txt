=== DataChat AI ===
Contributors: manudrago
Tags: analytics, dashboard, ai, charts, woocommerce
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ask your WordPress and WooCommerce data anything in plain English and get charts, tables and dashboards in wp-admin.

== Description ==

DataChat AI adds a question box to wp-admin. Type "how many orders did we take each month this
year?" and get a chart, a table and a CSV in seconds, then save the answer as a panel on a
dashboard.

How it works: the plugin sends the question and your database *structure* (table and column
names, types, and the descriptions you write) to the language model you choose. The model turns
the question into SQL; the plugin checks that SQL against a strict read-only guard and runs it on
your own database. To design the chart, a small sample of the result rows (30 by default) is sent
to the model as well. Charts are drawn as inline SVG, with no external chart library and no CDN.

Setup is one API key: Google AI Studio and Groq have free tiers, OpenAI is pay-as-you-go, and any
OpenAI-compatible endpoint works, including Ollama and LM Studio running on your own hardware, in
which case nothing leaves your network.

= What you get =

* Plain-English questions under DataChat → Ask, with follow-ups ("and last year?") and example
  questions.
* Charts you choose: columns, bars, line, area, pie and KPI, one click apart. Time always runs
  forwards, long labels turn the chart on its side, colours follow your theme.
* Tables, SQL on view and CSV export.
* Saved dashboards that re-run live (the free edition keeps two saved panels).
* WooCommerce ready: share the order and product tables and ask about sales, customers and stock.

= Safe by design =

* The model is treated as an untrusted source of SQL. Every statement must be a single
  SELECT/WITH, may only touch the tables you shared, may not reference blocked columns or system
  schemas, and always carries a LIMIT.
* Sensitive columns (passwords, emails, session tokens) are blocked and masked by default.
* Define WWD_DB_USER / WWD_DB_PASSWORD in wp-config.php to run every query as a read-only MySQL
  user.
* Asking requires a capability (default: edit_posts). Every question and statement is logged.

= Pro and Agency =

Paid editions, available from [ideagency.co.uk](https://ideagency.co.uk/our-plugins/), add
unlimited saved panels, scheduled email reports, shortcodes to show dashboards on your pages, the
AI included without an API key (Pro), and ten sites per licence with white label (Agency). This
free plugin is complete on its own and never contacts the shop.

== Installation ==

1. Install and activate the plugin.
2. DataChat → Settings: choose a provider, paste an API key (a free one from
   aistudio.google.com/apikey works) and press "Test connection".
3. DataChat → Data & schema: pick the tables to share and add a few lines of business context.
4. DataChat → Ask, and ask something.

== Frequently Asked Questions ==

= Which model does this need? =

Any of: Google AI Studio (free tier, the default), Groq (free tier), OpenAI, or anything that
speaks the OpenAI chat-completions API, including Ollama and LM Studio on your own hardware.

= What leaves my site? =

The question, the schema (table and column names, types, relationships and your descriptions) and
a sample of the result rows used to design the chart: 30 by default, adjustable with the
wwd_chart_sample_rows filter. Never the rest of your data: the SQL runs here. With a local model
(Ollama, LM Studio) nothing leaves your network.

= Can I use a Wren AI service instead? =

Yes. Pick that engine in Settings. It talks to the REST API of wren-ai-service (Wren AI
self-hosted "GenBI Classic" or Wren AI Cloud).

== External services ==

This plugin sends data to an external service only when you configure one, and only when someone
asks a question, tests the connection or lists models in Settings. Which service depends on the
provider you choose:

* **Google AI Studio (Gemini API)**, the default provider: generativelanguage.googleapis.com.
  Sent: the question, the schema of the tables you shared and up to 30 result rows.
  [Terms](https://ai.google.dev/gemini-api/terms), [Privacy](https://policies.google.com/privacy).
* **Groq**: api.groq.com. Same data.
  [Terms](https://groq.com/terms-of-use/), [Privacy](https://groq.com/privacy-policy/).
* **OpenAI**: api.openai.com. Same data.
  [Terms](https://openai.com/policies/terms-of-use/), [Privacy](https://openai.com/policies/privacy-policy/).
* **An OpenAI-compatible endpoint of your choice** (OpenRouter, Ollama, LM Studio…): the URL you
  enter. Same data; the terms are those of whoever runs that endpoint.
* **Wren AI**, only if you pick the Wren AI engine: the endpoint you enter, self-hosted or Wren AI
  Cloud. Sent: the question and the schema. [Wren AI](https://getwren.ai/), [Privacy](https://getwren.ai/privacy).
  Settings can also generate a pairing command for your own server; the installer it runs is
  downloaded by you from this plugin's public GitHub repository. The plugin only shows the command
  and links to the guide; it never downloads or runs anything itself.

No data is sent anywhere until a provider and key are configured.

== Changelog ==

= 2.5.0 =
* Scheduled email reports (Pro and Agency). Under any dashboard, choose daily, weekly or monthly,
  the hour and who gets it: the panels are re-run and arrive as figures, bar lists and tables that
  every email client shows. No model call is spent on them. "Send a test to me now" checks it.
* White label (Agency). Rename the plugin and change its menu icon for your clients: the admin
  menu, the screens and the email reports carry your name instead.
* Agency licences can cover several sites; the shop decides how many.
* Shortcodes are now part of Pro and Agency, and documented under their current names,
  [datachat] and [datachat_dashboard]. Without a licence a shortcode shows nothing to visitors and
  a one-line note to administrators. The old [wren_…] names keep working.
* Ready for WordPress.org: passes Plugin Check with no errors, documents every external service
  it can call, and the free edition shows no locked controls. Requires WordPress 6.2.
* Settings: choosing the model included with Pro hides the key, model and URL fields, which do not
  apply to it; picking another provider brings them back with that provider's defaults. The raw
  licence-server reply is no longer shown.

= 2.4.0 =
* Pro comes with a model. A Pro licence asks questions through the shop that sold it, so there
  is no API key to create anywhere: pick "DataChat AI - included with your Pro licence" in
  Settings (new Pro installs start on it) and ask. A monthly allowance of questions is included;
  past it, any provider with your own key carries on.
* The shop answers only for the site the licence is active on, and words its refusals for the
  customer - not active here, allowance used up - instead of reporting a bad API key nobody
  typed.
* Agency and the free edition bring their own key, as before.

= 2.3.0 =
* One licence, one site. The shop records the domain behind every check, refuses a second one and
  names the site already holding the licence, so a key bought once cannot run a dozen
  installations. A shop that sells wider licences can raise the count per product.
* Moving a licence needs no support ticket: "Remove from this site" now tells the shop to free the
  seat, and says plainly when it could not be reached. A site that stops checking in for sixty
  days gives its seat back on its own, so an abandoned install never traps a licence.
* The licence screen says how many of the sites a licence covers are in use, and which site holds
  it when a key is refused for being in use elsewhere.

= 2.2.0 =
* Licence answers are signed. The shop keeps an RSA key, generated on first use, and signs what
  it says; a paid build carries only the public half. So a forged answer, an answer flipped in
  flight, and a real answer captured and played back later are all refused - and none of them can
  lock a paying site out, because an answer that fails to verify counts as silence, not refusal.
* The shop reads Simple License Key for WooCommerce's own encrypted feed, where the passphrase can
  stay on the server instead of shipping inside a GPL plugin. A key that has left the feed is
  looked up in the order it was sold with, as before.

= 2.1.2 =
* Licence checks now go to a route on the shop that sold the key, which answers whether that key
  is valid for the site asking. deploy/license-endpoint.php is that route, to be installed on the
  shop; it looks the key up in the order it was sold with and never hands a key back out.
* The licence screen can show what the server last replied, so a refused key that should work can
  be diagnosed without guesswork.
* A shop that wraps its answer in a data object, or hands back a token, is understood. An answer
  that means nothing is still treated as silence rather than as a refusal.

= 2.1.1 =
* Installing a paid edition next to the free one no longer breaks the site. The second copy to
  load stands down and says which one to deactivate, instead of crashing on classes the other
  one already declared.

= 2.1.0 =
* Paid editions: one codebase, three builds. A Pro or Agency archive carries an edition marker
  and a licence screen; the free one has neither. A shop that is down or answers something
  unreadable never locks a paying site out - only an answer that actually refuses the key does,
  and a confirmed licence keeps working for two weeks of silence.

= 2.0.0 =
* New name: DataChat AI. The plugin no longer runs on Wren AI by default, and carrying
  somebody else's product name was misleading as well as risky. Wren AI remains one of the
  engines, named where it is meant.
* New shortcodes [datachat] and [datachat_dashboard]; the old ones keep working.
* The REST routes moved to /wp-json/datachat/v1, with the old namespace still answering.

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

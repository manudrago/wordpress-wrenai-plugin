#!/usr/bin/env python3
"""Turn Wren AI's stock config.example.yaml into a working configuration.

The pipeline section of that file changes between releases and every pipe must
be present or the service refuses to start, so this rewrites only the three
documents that describe *where* inference happens (llm, embedder,
document_store) and copies everything else through untouched.

Everything is expressed in LiteLLM terms, so the same script configures a local
Ollama, Google AI Studio, Groq or OpenAI - the installer decides which by
passing different model ids:

    # local Ollama
    python3 make-wren-config.py config.example.yaml \\
        --llm-model ollama_chat/qwen2.5-coder:7b \\
        --llm-api-base http://host.docker.internal:11434 \\
        --embed-model openai/nomic-embed-text \\
        --embed-api-base http://host.docker.internal:11434/v1 \\
        --embed-api-key-name LLM_OPENAI_API_KEY \\
        --embedding-dim 768 --output config.yaml

    # Google AI Studio (free tier)
    python3 make-wren-config.py config.example.yaml \\
        --llm-model gemini/gemini-3.6-flash --llm-api-key-name GEMINI_API_KEY \\
        --embed-model gemini/text-embedding-004 --embed-api-key-name GEMINI_API_KEY \\
        --embedding-dim 768 --output config.yaml

Every pipe in the stock file refers to `litellm_llm.default` and
`litellm_embedder.default`, so only the default alias is written.
"""

import argparse
import sys

try:
    import yaml
except ImportError:  # pragma: no cover - the installer apt-installs it first
    sys.exit("PyYAML is required: sudo apt-get install -y python3-yaml")


def build_llm(model: str, api_base: str, api_key_name: str, timeout: int) -> dict:
    """LLM document, in the shape wren-ai-service hands to LiteLLM."""
    entry = {
        "alias": "default",
        "model": model,
        "timeout": timeout,
        "kwargs": {"n": 1, "temperature": 0},
    }

    if api_base:
        entry["api_base"] = api_base

    # litellm_llm reads this env var itself and passes the value on. Hosted
    # providers also accept their canonical variable straight from the
    # environment, but being explicit survives a renamed variable.
    if api_key_name:
        entry["api_key_name"] = api_key_name

    return {
        "type": "llm",
        "provider": "litellm_llm",
        "timeout": timeout,
        "models": [entry],
    }


def build_embedder(model: str, api_base: str, api_key_name: str, timeout: int) -> dict:
    """Embedder document.

    Ollama's own LiteLLM route has a long-standing bug for embeddings, so an
    Ollama embedder is addressed through its OpenAI-compatible endpoint
    instead: model `openai/<name>` against `<url>/v1`. Hosted embedders use
    their native `<provider>/<model>` id and no api_base at all.
    """
    entry = {
        "alias": "default",
        "model": model,
        "timeout": timeout,
    }

    if api_base:
        entry["api_base"] = api_base

    if api_key_name:
        entry["api_key_name"] = api_key_name

    return {
        "type": "embedder",
        "provider": "litellm_embedder",
        "models": [entry],
    }


def trim_pipeline(settings: dict, engine_timeout: int) -> dict:
    """Trim the work each question costs.

    Every one of these flags buys quality with one or more extra LLM round
    trips. That is painful on four CPU cores and, on a free hosted tier, it is
    the difference between answering and hitting the per-minute rate limit.
    """
    settings["allow_intent_classification"] = False
    settings["allow_sql_generation_reasoning"] = False
    settings["allow_sql_functions_retrieval"] = False
    settings["langfuse_enable"] = False
    settings["logging_level"] = "INFO"
    settings["engine_timeout"] = engine_timeout

    return settings


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", help="path to the stock config.example.yaml")
    parser.add_argument("--llm-model", required=True, help="LiteLLM model id, e.g. gemini/gemini-3.6-flash")
    parser.add_argument("--llm-api-base", default="", help="only for self-hosted or OpenAI-compatible endpoints")
    parser.add_argument("--llm-api-key-name", default="", help="name of the .env variable holding the key")
    parser.add_argument("--embed-model", required=True, help="LiteLLM embedding model id")
    parser.add_argument("--embed-api-base", default="")
    parser.add_argument("--embed-api-key-name", default="")
    parser.add_argument("--embedding-dim", type=int, default=768)
    parser.add_argument("--timeout", type=int, default=600)
    parser.add_argument(
        "--engine-url",
        default="",
        help="Endpoint for the wren_ui engine, which Wren AI uses to dry-run the SQL it writes",
    )
    parser.add_argument("--output", required=True)
    parser.add_argument(
        "--full-pipeline",
        action="store_true",
        help="keep the upstream settings instead of trimming LLM round trips",
    )

    args = parser.parse_args()

    with open(args.source, "r", encoding="utf-8") as handle:
        documents = [doc for doc in yaml.safe_load_all(handle) if doc]

    rewritten = []
    seen = {"llm": False, "embedder": False, "document_store": False}

    for document in documents:
        kind = document.get("type")

        if kind == "llm":
            rewritten.append(
                build_llm(args.llm_model, args.llm_api_base, args.llm_api_key_name, args.timeout)
            )
            seen["llm"] = True
        elif kind == "embedder":
            rewritten.append(
                build_embedder(
                    args.embed_model, args.embed_api_base, args.embed_api_key_name, args.timeout
                )
            )
            seen["embedder"] = True
        elif kind == "engine" and document.get("provider") == "wren_ui" and args.engine_url:
            # Wren AI validates every statement it writes by dry-running it
            # through this engine. We do not run the UI, so point it at whatever
            # the caller provides - the plugin executes the SQL itself anyway,
            # behind its own guard.
            document["endpoint"] = args.engine_url.rstrip("/")
            rewritten.append(document)
        elif kind == "document_store":
            document["embedding_model_dim"] = args.embedding_dim
            document["timeout"] = args.timeout
            rewritten.append(document)
            seen["document_store"] = True
        elif "settings" in document and not args.full_pipeline:
            document["settings"] = trim_pipeline(document["settings"], min(args.timeout, 120))
            rewritten.append(document)
        else:
            rewritten.append(document)

    missing = [name for name, found in seen.items() if not found]

    if missing:
        sys.stderr.write(f"Unexpected source config: no {', '.join(missing)} section\n")

        return 1

    pipes = [doc for doc in rewritten if doc.get("type") == "pipeline"]

    if not pipes or not pipes[0].get("pipes"):
        sys.stderr.write("Unexpected source config: no pipeline definitions\n")

        return 1

    with open(args.output, "w", encoding="utf-8") as handle:
        handle.write(
            "# Generated by make-wren-config.py - change the model here, then\n"
            "# re-run the installer so the service picks it up.\n"
            "# The engine and pipeline sections come from Wren AI's own example\n"
            "# for this exact version; do not hand-edit them.\n"
        )
        yaml.safe_dump_all(rewritten, handle, sort_keys=False, default_flow_style=False)

    print(
        f"{args.output}: {len(rewritten)} sections, {len(pipes[0]['pipes'])} pipes, "
        f"llm={args.llm_model}, embedder={args.embed_model} ({args.embedding_dim} dims)"
    )

    return 0


if __name__ == "__main__":
    sys.exit(main())

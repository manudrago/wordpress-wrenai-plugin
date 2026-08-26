#!/usr/bin/env python3
"""Turn Wren AI's stock config.example.yaml into an Ollama configuration.

The pipeline section of that file changes between releases and every pipe must
be present or the service refuses to start, so this rewrites only the three
documents that describe *where* inference happens (llm, embedder,
document_store) and copies everything else through untouched.

    python3 make-ollama-config.py config.example.yaml \\
        --ollama-url http://172.17.0.1:11434 \\
        --model qwen2.5-coder:7b \\
        --embedder nomic-embed-text \\
        --embedding-dim 768 \\
        --output config.yaml
"""

import argparse
import sys

try:
    import yaml
except ImportError:  # pragma: no cover - the installer apt-installs it first
    sys.exit("PyYAML is required: sudo apt-get install -y python3-yaml")


def build_llm(url: str, model: str, timeout: int) -> dict:
    """LLM document pointing at Ollama through LiteLLM."""
    return {
        "type": "llm",
        "provider": "litellm_llm",
        "timeout": timeout,
        "models": [
            {
                "alias": "default",
                "model": f"ollama_chat/{model}",
                "api_base": url,
                "timeout": timeout,
                "kwargs": {"n": 1, "temperature": 0},
            }
        ],
    }


def build_embedder(url: str, model: str, timeout: int) -> dict:
    """Embedder document.

    Ollama's own LiteLLM route has a long-standing bug for embeddings, so the
    upstream example calls Ollama's OpenAI-compatible endpoint instead:
    model `openai/<name>` against `<url>/v1`.
    """
    return {
        "type": "embedder",
        "provider": "litellm_embedder",
        "models": [
            {
                "alias": "default",
                "model": f"openai/{model}",
                "api_base": f"{url}/v1",
                "api_key_name": "LLM_OPENAI_API_KEY",
                "timeout": timeout,
            }
        ],
    }


def tune_for_cpu(settings: dict) -> dict:
    """Trim the work a CPU-only model has to do.

    Each of these flags costs one or more extra LLM round trips per question,
    which is cheap on a hosted model and painful on four ARM cores.
    """
    settings["allow_intent_classification"] = False
    settings["allow_sql_generation_reasoning"] = False
    settings["allow_sql_functions_retrieval"] = False
    settings["langfuse_enable"] = False
    settings["logging_level"] = "INFO"
    settings["engine_timeout"] = 120

    return settings


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", help="path to the stock config.example.yaml")
    parser.add_argument("--ollama-url", required=True, help="e.g. http://172.17.0.1:11434")
    parser.add_argument("--model", required=True, help="Ollama chat model, e.g. qwen2.5-coder:7b")
    parser.add_argument("--embedder", default="nomic-embed-text")
    parser.add_argument("--embedding-dim", type=int, default=768)
    parser.add_argument("--timeout", type=int, default=600)
    parser.add_argument(
        "--engine-url",
        default="",
        help="Endpoint for the wren_ui engine, which Wren AI uses to dry-run the SQL it writes",
    )
    parser.add_argument("--output", required=True)
    parser.add_argument(
        "--no-cpu-tuning",
        action="store_true",
        help="keep the upstream settings instead of trimming LLM round trips",
    )

    args = parser.parse_args()
    url = args.ollama_url.rstrip("/")

    with open(args.source, "r", encoding="utf-8") as handle:
        documents = [doc for doc in yaml.safe_load_all(handle) if doc]

    rewritten = []
    seen = {"llm": False, "embedder": False, "document_store": False}

    for document in documents:
        kind = document.get("type")

        if kind == "llm":
            rewritten.append(build_llm(url, args.model, args.timeout))
            seen["llm"] = True
        elif kind == "embedder":
            rewritten.append(build_embedder(url, args.embedder, args.timeout))
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
        elif "settings" in document and not args.no_cpu_tuning:
            document["settings"] = tune_for_cpu(document["settings"])
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
            "# Generated by make-ollama-config.py - edit ollama model/url here.\n"
            "# The engine and pipeline sections come from Wren AI's own example\n"
            "# for this exact version; do not hand-edit them.\n"
        )
        yaml.safe_dump_all(rewritten, handle, sort_keys=False, default_flow_style=False)

    print(f"{args.output}: {len(rewritten)} sections, {len(pipes[0]['pipes'])} pipes")

    return 0


if __name__ == "__main__":
    sys.exit(main())

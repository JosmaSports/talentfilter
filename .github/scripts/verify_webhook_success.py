#!/usr/bin/env python3
"""
Verifica que la respuesta del webhook de deploy contiene un JSON con success:true.

Tolera que el cuerpo tenga "ruido" antes del JSON (warnings/notices de PHP en HTML)
buscando el primer objeto JSON balanceado a partir del primer '{'.

Uso:
    cat response_body | python3 verify_webhook_success.py

Sale con código 0 si encuentra success:true, 1 en caso contrario.
"""
from __future__ import annotations

import json
import sys


def extract_first_json_object(text: str) -> dict | None:
    start = text.find("{")
    if start == -1:
        return None

    depth = 0
    in_string = False
    escape = False

    for i in range(start, len(text)):
        ch = text[i]

        if in_string:
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == '"':
                in_string = False
            continue

        if ch == '"':
            in_string = True
        elif ch == "{":
            depth += 1
        elif ch == "}":
            depth -= 1
            if depth == 0:
                candidate = text[start : i + 1]
                try:
                    parsed = json.loads(candidate)
                except json.JSONDecodeError:
                    next_start = text.find("{", start + 1)
                    if next_start == -1:
                        return None
                    return extract_first_json_object(text[next_start:])
                if isinstance(parsed, dict):
                    return parsed
                return None

    return None


def main() -> int:
    body = sys.stdin.read()

    if not body.strip():
        print("verify_webhook_success: cuerpo vacío", file=sys.stderr)
        return 1

    try:
        parsed = json.loads(body)
        if isinstance(parsed, dict) and parsed.get("success") is True:
            return 0
    except json.JSONDecodeError:
        pass

    obj = extract_first_json_object(body)
    if obj is None:
        print("verify_webhook_success: no se encontró JSON en la respuesta", file=sys.stderr)
        return 1

    if obj.get("success") is True:
        return 0

    print(
        f"verify_webhook_success: success != true (recibido: {obj.get('success')!r}, "
        f"message: {obj.get('message')!r})",
        file=sys.stderr,
    )
    return 1


if __name__ == "__main__":
    sys.exit(main())

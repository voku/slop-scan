# SLOP-1: Port upstream api.generic-status-envelopes as php.generic-status-envelopes

Port the upstream `api.generic-status-envelopes` check from
`modem-dev/slop-scan` (TypeScript) to a PHP-native rule in this repository.

Upstream flags object literals that combine a boolean status field
(`success` / `ok`) with a generic payload field (`message`, `error`, `data`,
`rows`, ...). The PHP analog is the associative array literal that repeats the
same shape, which is the dominant "generic service glue" idiom in PHP.

The rule must stay explainable and deterministic like every other rule here:
AST-backed, file scope, stable evidence, capped score.

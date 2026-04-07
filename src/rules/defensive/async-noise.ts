import type { RulePlugin } from "../../core/types";
import type { FunctionSummary } from "../../facts/types";
import { isBoundaryWrapperTarget } from "../helpers";

export const asyncNoiseRule: RulePlugin = {
  id: "defensive.async-noise",
  family: "defensive",
  severity: "medium",
  scope: "file",
  requires: ["file.functionSummaries"],
  supports(context) {
    return context.scope === "file" && Boolean(context.file);
  },
  evaluate(context) {
    const functions =
      context.runtime.store.getFileFact<FunctionSummary[]>(context.file!.path, "file.functionSummaries") ?? [];

    const redundantReturnAwait = functions.filter((summary) => summary.hasReturnAwaitCall);
    const asyncPassThroughWrappers = functions.filter(
      (summary) => summary.isAsync
        && !summary.hasAwait
        && summary.isPassThroughWrapper
        && !summary.hasReturnAwaitCall
        && !isBoundaryWrapperTarget(summary.passThroughTarget),
    );
    const noisy = [...redundantReturnAwait, ...asyncPassThroughWrappers];

    if (noisy.length === 0) {
      return [];
    }

    const score = Math.min(4, redundantReturnAwait.length * 1.5 + asyncPassThroughWrappers.length * 0.75);

    return [
      {
        ruleId: "defensive.async-noise",
        family: "defensive",
        severity: "medium",
        scope: "file",
        path: context.file!.path,
        message: `Found ${noisy.length} async-noise pattern${noisy.length === 1 ? "" : "s"}`,
        evidence: noisy.map((summary) => {
          const kind = summary.hasReturnAwaitCall ? "return-await" : "async-pass-through";
          return `${summary.name} at line ${summary.line} (${kind})`;
        }),
        score,
        locations: noisy.map((summary) => ({ path: context.file!.path, line: summary.line })),
      },
    ];
  },
};

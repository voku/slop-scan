import type { RulePlugin } from "../../core/types";
import type { TryCatchSummary } from "../../facts/types";

function isNeedless(summary: TryCatchSummary): boolean {
  return (
    summary.hasCatchClause &&
    summary.tryStatementCount <= 2 &&
    (
      summary.catchLogsOnly ||
      summary.catchReturnsDefault ||
      summary.catchIsEmpty ||
      summary.catchThrowsGeneric ||
      (summary.catchHasLogging && summary.catchHasDefaultReturn)
    )
  );
}

function scoreTryCatch(summary: TryCatchSummary): number {
  let score = 3;

  if (summary.catchIsEmpty) {
    score += 0.5;
  }

  if (summary.catchThrowsGeneric) {
    score -= 0.5;
  }

  if (summary.boundaryCategories.length > 0) {
    score *= 0.4;
    if (summary.catchIsEmpty) {
      score += 0.5;
    }
  }

  return Math.max(0.75, score);
}

export const needlessTryCatchRule: RulePlugin = {
  id: "defensive.needless-try-catch",
  family: "defensive",
  severity: "strong",
  scope: "file",
  requires: ["file.tryCatchSummaries"],
  supports(context) {
    return context.scope === "file" && Boolean(context.file);
  },
  evaluate(context) {
    const summaries =
      context.runtime.store.getFileFact<TryCatchSummary[]>(context.file!.path, "file.tryCatchSummaries") ?? [];

    const flagged = summaries.filter(isNeedless);

    if (flagged.length === 0) {
      return [];
    }

    return [
      {
        ruleId: "defensive.needless-try-catch",
        family: "defensive",
        severity: "strong",
        scope: "file",
        path: context.file!.path,
        message: `Found ${flagged.length} defensive try/catch block${flagged.length === 1 ? "" : "s"}`,
        evidence: flagged.map((summary) => {
          const boundary = summary.boundaryCategories.length > 0 ? summary.boundaryCategories.join("|") : "none";
          return `line ${summary.line}: try=${summary.tryStatementCount}, catch=${summary.catchStatementCount}, logsOnly=${summary.catchLogsOnly}, returnsDefault=${summary.catchReturnsDefault}, boundary=${boundary}`;
        }),
        score: Math.min(8, flagged.reduce((total, summary) => total + scoreTryCatch(summary), 0)),
        locations: flagged.map((summary) => ({ path: context.file!.path, line: summary.line })),
      },
    ];
  },
};

import type { RulePlugin } from "../../core/types";
import type { CommentSummary } from "../../facts/types";

const PLACEHOLDER_PATTERNS = [
  /add\s+more\s+validation/i,
  /handle\s+(?:additional|more)\s+cases?/i,
  /can\s+be\s+extended\s+in\s+the\s+future/i,
  /extend\s+this\s+(?:logic|function|method|handler|module)/i,
  /customize\s+this\s+(?:logic|behavior|function|method|handler)/i,
  /future\s+enhancement/i,
  /implement\s+.+\s+here/i,
];

export const placeholderCommentsRule: RulePlugin = {
  id: "comments.placeholder-comments",
  family: "comments",
  severity: "weak",
  scope: "file",
  requires: ["file.comments"],
  supports(context) {
    return context.scope === "file" && Boolean(context.file);
  },
  evaluate(context) {
    const comments =
      context.runtime.store.getFileFact<CommentSummary[]>(context.file!.path, "file.comments") ?? [];
    const matches = comments.filter((comment) =>
      PLACEHOLDER_PATTERNS.some((pattern) => pattern.test(comment.text)),
    );

    if (matches.length === 0) {
      return [];
    }

    return [
      {
        ruleId: "comments.placeholder-comments",
        family: "comments",
        severity: "weak",
        scope: "file",
        path: context.file!.path,
        message: `Found ${matches.length} placeholder-style comments`,
        evidence: matches.map((match) => match.text),
        score: Math.min(1.5, matches.length * 0.75),
        locations: matches.map((match) => ({ path: context.file!.path, line: match.line })),
      },
    ];
  },
};

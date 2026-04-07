import type { RulePlugin } from "../../core/types";
import { isTestFile } from "../../facts/ts-helpers";
import type { DirectoryMetrics } from "../../facts/types";
import {
  average,
  countMatching,
  isAssetLikeDirectoryPath,
  median,
  parentDirectoryPath,
  ratio,
} from "../helpers";

export const directoryFanoutHotspotRule: RulePlugin = {
  id: "structure.directory-fanout-hotspot",
  family: "structure",
  severity: "medium",
  scope: "directory",
  requires: ["directory.metrics"],
  supports(context) {
    return context.scope === "directory" && Boolean(context.directory);
  },
  evaluate(context) {
    const metrics =
      context.runtime.store.getDirectoryFact<DirectoryMetrics>(context.directory!.path, "directory.metrics") ?? null;

    if (!metrics || context.directory!.path === ".") {
      return [];
    }

    const testFileRatio = ratio(
      countMatching(context.directory!.filePaths, (filePath) => isTestFile(filePath)),
      metrics.fileCount,
    );
    if (testFileRatio >= 0.8 || isAssetLikeDirectoryPath(context.directory!.path)) {
      return [];
    }

    const parentPath = parentDirectoryPath(context.directory!.path);
    const siblingCounts = context.runtime.directories
      .filter((directory) => parentDirectoryPath(directory.path) === parentPath)
      .map((directory) => context.runtime.store.getDirectoryFact<DirectoryMetrics>(directory.path, "directory.metrics")?.fileCount ?? 0)
      .filter((value) => value > 0);
    const globalCounts = context.runtime.directories
      .map((directory) => context.runtime.store.getDirectoryFact<DirectoryMetrics>(directory.path, "directory.metrics")?.fileCount ?? 0)
      .filter((value) => value > 0);

    const localBaseline = siblingCounts.length >= 3 ? median(siblingCounts) : 0;
    const globalBaseline = average(globalCounts);
    const baseline = localBaseline > 0 ? localBaseline : globalBaseline;
    const threshold = Math.max(6, Math.ceil(baseline * (localBaseline > 0 ? 2.25 : 2.5)));

    if (metrics.fileCount < threshold) {
      return [];
    }

    return [
      {
        ruleId: "structure.directory-fanout-hotspot",
        family: "structure",
        severity: "medium",
        scope: "directory",
        path: context.directory!.path,
        message: `Directory fan-out is a repo hotspot (${metrics.fileCount} files vs baseline ${baseline.toFixed(1)})`,
        evidence: [
          `baseline=${baseline.toFixed(2)}`,
          `threshold=${threshold}`,
          `testFileRatio=${testFileRatio.toFixed(2)}`,
          `fileCount=${metrics.fileCount}`,
        ],
        score: 2 + Math.min(4, metrics.fileCount / Math.max(1, threshold)),
        locations: [{ path: context.directory!.path, line: 1 }],
      },
    ];
  },
};

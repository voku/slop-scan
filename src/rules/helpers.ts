import path from "node:path";

const ASSET_LIKE_DIRECTORY_SEGMENTS = new Set(["icon", "icons", "svg", "svgs", "asset", "assets"]);
const BOUNDARY_WRAPPER_TARGET_PREFIXES = [
  "prisma.",
  "redis.",
  "jwt.",
  "bcrypt.",
  "response.",
  "Response.",
  "fetch",
  "axios.",
  "crypto.",
  "storage.",
];

export function ratio(count: number, total: number): number {
  return total === 0 ? 0 : count / total;
}

export function countMatching<T>(values: T[], predicate: (value: T) => boolean): number {
  return values.reduce((total, value) => total + (predicate(value) ? 1 : 0), 0);
}

export function average(values: number[]): number {
  if (values.length === 0) {
    return 0;
  }

  return values.reduce((sum, value) => sum + value, 0) / values.length;
}

export function median(values: number[]): number {
  if (values.length === 0) {
    return 0;
  }

  const sorted = [...values].sort((left, right) => left - right);
  const middle = Math.floor(sorted.length / 2);

  if (sorted.length % 2 === 1) {
    return sorted[middle] ?? 0;
  }

  const left = sorted[middle - 1] ?? 0;
  const right = sorted[middle] ?? 0;
  return (left + right) / 2;
}

export function isAssetLikeDirectoryPath(directoryPath: string): boolean {
  return directoryPath
    .split("/")
    .map((segment) => segment.toLowerCase())
    .some((segment) => ASSET_LIKE_DIRECTORY_SEGMENTS.has(segment));
}

export function parentDirectoryPath(directoryPath: string): string {
  return path.posix.dirname(directoryPath);
}

export function isBoundaryWrapperTarget(target: string | null): boolean {
  if (!target) {
    return false;
  }

  return BOUNDARY_WRAPPER_TARGET_PREFIXES.some((prefix) => target.startsWith(prefix));
}

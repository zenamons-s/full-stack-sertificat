import type { CertificateStatus } from "@/lib/types";

export type CertificateListParams = {
  search?: string;
  status?: CertificateStatus;
  trashed: "none" | "with" | "only";
  page: number;
  per_page: number;
  sort: "created_at" | "-created_at" | "expires_at" | "-expires_at" | "price_minor" | "-price_minor" | "title" | "-title";
};

const statuses = ["active", "expired", "redeemed", "cancelled"] as const;
const trashed = ["none", "with", "only"] as const;
const sorts = ["created_at", "-created_at", "expires_at", "-expires_at", "price_minor", "-price_minor", "title", "-title"] as const;

export function normalizeListParams(input: Record<string, string | string[] | undefined>): CertificateListParams {
  const value = (key: string) => {
    const raw = input[key];
    return Array.isArray(raw) ? raw[0] : raw;
  };
  const search = value("search")?.trim() || undefined;
  const statusRaw = value("status");
  const trashedRaw = value("trashed");
  const sortRaw = value("sort");
  const page = positiveInt(value("page"), 1, 1, Number.MAX_SAFE_INTEGER);
  const perPage = positiveInt(value("per_page"), 20, 1, 100);
  return {
    search,
    status: statuses.includes(statusRaw as CertificateStatus) ? (statusRaw as CertificateStatus) : undefined,
    trashed: trashed.includes(trashedRaw as CertificateListParams["trashed"]) ? (trashedRaw as CertificateListParams["trashed"]) : "none",
    page,
    per_page: perPage,
    sort: sorts.includes(sortRaw as CertificateListParams["sort"]) ? (sortRaw as CertificateListParams["sort"]) : "-created_at",
  };
}

export function toQueryString(params: CertificateListParams): string {
  const query = new URLSearchParams();
  if (params.search) query.set("search", params.search);
  if (params.status) query.set("status", params.status);
  if (params.trashed !== "none") query.set("trashed", params.trashed);
  if (params.page !== 1) query.set("page", String(params.page));
  if (params.per_page !== 20) query.set("per_page", String(params.per_page));
  if (params.sort !== "-created_at") query.set("sort", params.sort);
  return query.toString();
}

export function mergeListParams(
  currentEntries: Iterable<[string, string]>,
  patch: Partial<CertificateListParams>,
): CertificateListParams {
  const raw: Record<string, string | undefined> = Object.fromEntries(currentEntries);

  for (const [key, value] of Object.entries(patch)) {
    if (value === undefined) {
      delete raw[key];
      continue;
    }

    raw[key] = String(value);
  }

  return normalizeListParams(raw);
}

function positiveInt(value: string | undefined, fallback: number, min: number, max: number): number {
  const parsed = Number(value);
  if (!Number.isInteger(parsed) || parsed < min) return fallback;
  return Math.min(parsed, max);
}

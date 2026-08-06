import { Suspense } from "react";
import { CertificatesClient } from "@/components/certificates-client";
import { normalizeListParams, toQueryString } from "@/lib/list-params";
import { serverFetch } from "@/lib/server-fetch";
import type { CertificateStatus, PaginatedCertificates } from "@/lib/types";

export const dynamic = "force-dynamic";

type Props = {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
};

export default async function CertificatesPage({ searchParams }: Props) {
  const params = normalizeListParams(await searchParams);
  const query = toQueryString(params);
  let initialData: PaginatedCertificates;
  let initialStatusCounts: StatusCounts;
  try {
    [initialData, initialStatusCounts] = await Promise.all([
      serverFetch<PaginatedCertificates>(`/certificates${query ? `?${query}` : ""}`, { noStore: true }),
      fetchStatusCounts(),
    ]);
  } catch (error) {
    if (isRedirectError(error)) {
      throw error;
    }
    return (
      <div className="rounded-md border border-red-200 bg-red-50 p-5 text-sm text-red-800">
        <h1 className="text-base font-semibold">Не удалось загрузить интерфейс</h1>
        <p className="mt-2">Проверьте соединение и попробуйте ещё раз.</p>
      </div>
    );
  }

  return (
    <Suspense fallback={<div className="h-64 rounded-md border bg-white p-6 text-sm text-slate-500">Загрузка...</div>}>
      <CertificatesClient initialData={initialData} initialParams={params} initialStatusCounts={initialStatusCounts} />
    </Suspense>
  );
}

type StatusCounts = Record<Extract<CertificateStatus, "active" | "expired" | "redeemed">, number>;

async function fetchStatusCounts(): Promise<StatusCounts> {
  const [active, expired, redeemed] = await Promise.all([
    fetchStatusTotal("active"),
    fetchStatusTotal("expired"),
    fetchStatusTotal("redeemed"),
  ]);

  return { active, expired, redeemed };
}

async function fetchStatusTotal(status: keyof StatusCounts): Promise<number> {
  const result = await serverFetch<PaginatedCertificates>(`/certificates?status=${status}&per_page=1`, { noStore: true });
  return result.meta.total;
}

function isRedirectError(error: unknown): boolean {
  return typeof error === "object" && error !== null && "digest" in error && String((error as { digest: unknown }).digest).startsWith("NEXT_REDIRECT");
}

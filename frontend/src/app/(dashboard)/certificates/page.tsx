import { Suspense } from "react";
import { AppShell } from "@/components/app-shell";
import { CertificatesClient } from "@/components/certificates-client";
import { normalizeListParams, toQueryString } from "@/lib/list-params";
import { serverFetch } from "@/lib/server-fetch";
import type { PaginatedCertificates } from "@/lib/types";

export const dynamic = "force-dynamic";

type Props = {
  searchParams: Promise<Record<string, string | string[] | undefined>>;
};

export default async function CertificatesPage({ searchParams }: Props) {
  const params = normalizeListParams(await searchParams);
  const query = toQueryString(params);
  const initialData = await serverFetch<PaginatedCertificates>(`/certificates${query ? `?${query}` : ""}`, { noStore: true });

  return (
    <AppShell>
      <Suspense fallback={<div className="h-64 rounded-md border bg-white p-6 text-sm text-slate-500">Загрузка...</div>}>
        <CertificatesClient initialData={initialData} initialParams={params} />
      </Suspense>
    </AppShell>
  );
}

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
  let initialData: PaginatedCertificates;
  try {
    initialData = await serverFetch<PaginatedCertificates>(`/certificates${query ? `?${query}` : ""}`, { noStore: true });
  } catch (error) {
    if (isRedirectError(error)) {
      throw error;
    }
    return (
      <AppShell>
        <div className="rounded-md border border-red-200 bg-red-50 p-5 text-sm text-red-800">
          <h1 className="text-base font-semibold">Не удалось загрузить интерфейс</h1>
          <p className="mt-2">Проверьте соединение и попробуйте ещё раз.</p>
        </div>
      </AppShell>
    );
  }

  return (
    <AppShell>
      <Suspense fallback={<div className="h-64 rounded-md border bg-white p-6 text-sm text-slate-500">Загрузка...</div>}>
        <CertificatesClient initialData={initialData} initialParams={params} />
      </Suspense>
    </AppShell>
  );
}

function isRedirectError(error: unknown): boolean {
  return typeof error === "object" && error !== null && "digest" in error && String((error as { digest: unknown }).digest).startsWith("NEXT_REDIRECT");
}

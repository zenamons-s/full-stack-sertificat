import { notFound } from "next/navigation";
import { CertificateForm } from "@/components/certificate-form";
import { formatDateTime } from "@/lib/format";
import { ApiProblemError } from "@/lib/problem-details";
import { serverFetch } from "@/lib/server-fetch";
import type { Certificate, PaginatedAuditRecords } from "@/lib/types";

export const dynamic = "force-dynamic";

type Props = {
  params: Promise<{ id: string }>;
};

export default async function EditCertificatePage({ params }: Props) {
  const { id } = await params;
  let certificate: Certificate;
  let audit: PaginatedAuditRecords;
  try {
    certificate = await serverFetch<Certificate>(`/certificates/${encodeURIComponent(id)}`, { noStore: true });
    audit = await serverFetch<PaginatedAuditRecords>(`/certificates/${encodeURIComponent(id)}/audit?per_page=10`, { noStore: true });
  } catch (error) {
    if (error instanceof ApiProblemError && error.problem.status === 404) {
      notFound();
    }
    throw error;
  }
  return (
    <>
      <h1 className="mb-4 text-xl font-semibold">Редактирование</h1>
      <CertificateForm certificate={certificate} />
      <section className="mt-6 max-w-4xl rounded-md border bg-white">
        <div className="border-b px-5 py-3">
          <h2 className="text-base font-semibold">История изменений</h2>
        </div>
        {audit.data.length === 0 ? (
          <p className="px-5 py-4 text-sm text-slate-500">Записей пока нет.</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase text-slate-500">
                <tr>
                  <th className="px-5 py-3">Время</th>
                  <th className="px-5 py-3">Действие</th>
                  <th className="px-5 py-3">actor_type</th>
                  <th className="px-5 py-3">actor_id</th>
                </tr>
              </thead>
              <tbody className="divide-y">
                {audit.data.map((item) => (
                  <tr key={item.id}>
                    <td className="px-5 py-3">{formatDateTime(item.created_at)}</td>
                    <td className="px-5 py-3 font-medium">{item.action}</td>
                    <td className="px-5 py-3">{item.actor_type}</td>
                    <td className="px-5 py-3">{item.actor_id ?? "-"}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}

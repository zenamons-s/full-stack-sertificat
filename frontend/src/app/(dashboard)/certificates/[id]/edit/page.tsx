import { notFound } from "next/navigation";
import { AppShell } from "@/components/app-shell";
import { CertificateForm } from "@/components/certificate-form";
import { ApiProblemError } from "@/lib/problem-details";
import { serverFetch } from "@/lib/server-fetch";
import type { Certificate } from "@/lib/types";

export const dynamic = "force-dynamic";

type Props = {
  params: Promise<{ id: string }>;
};

export default async function EditCertificatePage({ params }: Props) {
  const { id } = await params;
  let certificate: Certificate;
  try {
    certificate = await serverFetch<Certificate>(`/certificates/${encodeURIComponent(id)}`, { noStore: true });
  } catch (error) {
    if (error instanceof ApiProblemError && error.problem.status === 404) {
      notFound();
    }
    throw error;
  }
  return (
    <AppShell>
      <h1 className="mb-4 text-xl font-semibold">Редактирование</h1>
      <CertificateForm certificate={certificate} />
    </AppShell>
  );
}

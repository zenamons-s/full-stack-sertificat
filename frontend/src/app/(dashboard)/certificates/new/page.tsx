import { AppShell } from "@/components/app-shell";
import { CertificateForm } from "@/components/certificate-form";

export default function NewCertificatePage() {
  return (
    <AppShell>
      <h1 className="mb-4 text-xl font-semibold">Новый сертификат</h1>
      <CertificateForm />
    </AppShell>
  );
}

import Link from "next/link";

export default function NotFound() {
  return (
    <main className="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-6">
      <h1 className="text-2xl font-semibold">Страница не найдена</h1>
      <p className="mt-3 text-sm text-slate-600">Запрошенный раздел не существует или был удалён.</p>
      <Link className="mt-6 rounded-md bg-blue-600 px-4 py-2 text-center text-white" href="/certificates">К списку</Link>
    </main>
  );
}

import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { getNeonAuth } from "@/lib/auth/server";
import { AccountPanel } from "./account-panel";

export const metadata: Metadata = { title: "Tài khoản của tôi", robots: { index: false, follow: false } };
export const dynamic = "force-dynamic";

export default async function AccountPage() {
  let identity: { name?: string | null; email?: string | null } | null = null;
  try {
    const result = await getNeonAuth().getSession();
    if (!result.error && result.data?.user) identity = { name: result.data.user.name, email: result.data.user.email };
  } catch {
    identity = null;
  }
  if (!identity) redirect("/sign-in?redirect=%2Faccount");
  return <main><AccountPanel initialIdentity={identity} /></main>;
}

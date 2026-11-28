"use client";

import { useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { authClient } from "@/lib/auth/client";

type AccountIdentity = { name?: string | null; email?: string | null };
type BackendUser = { id: number; name: string; email: string; role: string };
type BackendPayload = { ok?: boolean; error?: string; data?: { user?: BackendUser | null; csrfToken?: string } };

export function AccountPanel({ initialIdentity }: { initialIdentity: AccountIdentity }) {
  const router = useRouter();
  const [user, setUser] = useState<BackendUser | null>(null);
  const [csrf, setCsrf] = useState("");
  const [message, setMessage] = useState("Đang xác minh tài khoản với backend…");
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    let active = true;
    (async () => {
      try {
        const sessionResult = await authClient.getSession();
        if (sessionResult.error || !sessionResult.data?.user) {
          router.replace("/sign-in?redirect=%2Faccount");
          return;
        }
        const tokenResult = await authClient.token();
        const token = tokenResult.data?.token;
        if (tokenResult.error || !token) throw new Error("Neon Auth chưa cấp JWT.");
        const response = await fetch("/api.php?action=bootstrap", {
          credentials: "same-origin", cache: "no-store",
          headers: { Accept: "application/json", Authorization: "Bearer " + token },
        });
        const payload = await response.json() as BackendPayload;
        if (!response.ok || !payload.ok || !payload.data?.user) {
          throw new Error(payload.error || "Backend không xác nhận được phiên khách hàng.");
        }
        if (payload.data.user.role !== "customer") throw new Error("Tài khoản không có quyền truy cập khu vực khách hàng.");
        if (active) {
          setUser(payload.data.user);
          setCsrf(payload.data.csrfToken || "");
          setMessage("Tài khoản đã được xác thực.");
        }
      } catch (issue) {
        if (active) setMessage(issue instanceof Error ? issue.message : "Không thể tải tài khoản.");
      }
    })();
    return () => { active = false; };
  }, [router]);

  async function logout() {
    setBusy(true); setMessage("Đang đăng xuất…");
    try {
      if (csrf) {
        await fetch("/api.php?action=logout", {
          method: "POST", credentials: "same-origin",
          headers: { Accept: "application/json", "Content-Type": "application/json", "X-CSRF-Token": csrf },
          body: "{}",
        });
      }
      const result = await authClient.signOut();
      if (result.error) throw new Error(result.error.message || "Đăng xuất Neon Auth thất bại.");
      router.replace("/sign-in");
      router.refresh();
    } catch (issue) {
      setMessage(issue instanceof Error ? issue.message : "Không thể đăng xuất.");
      setBusy(false);
    }
  }

  return (
    <section className="auth-shell">
      <div className="auth-card account-card">
        <span className="kicker">HENDY STORE · MY ACCOUNT</span>
        <h1>Thư viện của bạn</h1>
        <p>{message}</p>
        <div className="account-info">
          <strong>{user?.name || initialIdentity.name || "Khách hàng"}</strong>
          <span>{user?.email || initialIdentity.email || "Đang xác minh email…"}</span>
          <span>Vai trò: {user?.role || "Đang xác minh"}</span>
        </div>
        <div className="account-actions">
          <a className="button" href="https://hendy-store.onrender.com/library/">Mở thư viện</a>
          <button className="account-logout" type="button" disabled={busy} onClick={logout}>Đăng xuất</button>
        </div>
      </div>
    </section>
  );
}

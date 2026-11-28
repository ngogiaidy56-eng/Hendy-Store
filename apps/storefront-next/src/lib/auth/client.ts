"use client";

import { createAuthClient } from "@neon/auth/next";

/** Same-origin Auth client; no private provider credential is shipped to browsers. */
export const authClient = createAuthClient();

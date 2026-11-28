-- Expose only approved, published catalogue fields through Neon Data API.
-- Never grant Data API roles direct access to products or private account tables.
CREATE OR REPLACE VIEW public.api_public_catalog
WITH (security_barrier = true)
AS
SELECT
  id,
  slug,
  title,
  developer,
  category,
  short_description,
  version,
  platform,
  icon,
  cover,
  downloads,
  rating,
  featured,
  updated_at,
  price_type,
  price_amount_vnd
FROM public.products
WHERE status = 'published';

COMMENT ON VIEW public.api_public_catalog IS
  'Read-only public catalogue projection for Neon Data API. Excludes package URLs and all private/account/order/session data.';

REVOKE ALL ON TABLE public.api_public_catalog FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO anonymous, authenticated;
GRANT SELECT ON TABLE public.api_public_catalog TO anonymous, authenticated;

-- Make the new view visible to PostgREST without granting access to base tables.
NOTIFY pgrst, 'reload schema';

-- Record the migration for clean installs and repeatable deployments.
INSERT INTO public.app_schema_migrations (migration_key, applied_at)
VALUES ('20261128_public_catalog_data_api', NOW())
ON CONFLICT (migration_key) DO NOTHING;

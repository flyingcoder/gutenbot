# Supabase as the Vector Store — Design Considerations

## Why scanned site data is pushed to Supabase (not kept only in WordPress/MySQL)

### Reason 1 — Cross-organisation data intelligence

The gutenbot platform is designed to serve multiple WordPress installations. By centralising scan results in Supabase we can build two layers of intelligence that a single-site database can never provide:

**Organisation-wide data**
Each site's scan captures that organisation's block usage, design tokens, section patterns, and page structures. Aggregating these across a client account lets the AI understand the organisation's house style — preferred layouts, tone through section-type frequency, design token palette — so generated pages are on-brand without manual prompt engineering.

**Niche-specific data**
When many organisations in the same niche (e.g. local plumbers, law firms, SaaS landing pages) contribute scan data, gutenbot can learn how that niche typically structures its websites. This lets the AI suggest layouts that are proven to work for that niche, not just layouts that are generically valid Gutenberg output.

Without a shared remote store, each WordPress install is an island and neither of these intelligence layers can be built.

### Reason 2 — MySQL cannot store vector embeddings correctly

WordPress runs on MySQL / MariaDB, which has no native vector type. Block patterns, design tokens, and page sections need to be embedded as high-dimensional float vectors (e.g. 1536 dimensions for `text-embedding-3-small`) so the AI can perform semantic similarity search at query time.

MySQL workarounds (storing vectors as `LONGTEXT` or `BLOB`) are unusable in practice:
- No index support — every similarity query becomes a full-table scan.
- Cosine / dot-product distance cannot be computed by MySQL natively.
- Deserialising a 1536-float JSON string on every row for every query is prohibitively slow.

Supabase uses PostgreSQL with the `pgvector` extension, which provides a dedicated `vector` column type, approximate nearest-neighbour indexes (`ivfflat`, `hnsw`), and built-in distance operators (`<=>`, `<#>`, `<+>`). This is the correct tool for the job.

---

## Summary

| Concern | MySQL / wp_options | Supabase + pgvector |
|---|---|---|
| Single-site scan progress | Suitable | Overkill |
| Cross-site / niche aggregation | Not possible | Native |
| Vector similarity search | Not possible | Native |
| Embeddings storage | Lossy / unusably slow | Correct type + indexed |

The WordPress database remains the right place for scan *progress and status* (fast, no network, survives offline). Supabase is the right place for the *enriched, embedded, shared* representation of that data.

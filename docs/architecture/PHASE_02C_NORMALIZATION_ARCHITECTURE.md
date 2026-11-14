# Phase 02C Normalization Architecture

## Source of truth

Committed Phase 02B `ingestion_records` (mock fixtures). Normalization never mutates staging rows, checkpoints, or leases.

## Module layout

```
App\Normalization\
  Contracts\NormalizerInterface
  DTOs\NormalizedContent, NormalizedMetric
  Enums\NormalizationRunStatus, NormalizationErrorCode, NormalizationTarget
  Jobs\NormalizationRunJob
  Mappers\{WordPressPost,WooCommerceProduct,TutorCourse,GscMetric,Ga4Metric}Normalizer
  Services\{NormalizerRegistry,NormalizationOrchestrator,NormalizationRunner,
            ContentProjectionService,MetricProjectionService,
            NormalizationQualityService,InventoryQueryService}
```

## Flow

1. `POST /api/v1/data-sources/{id}/normalize` → authZ → create `normalization_runs` (queued) → dispatch job → **202**
2. Job re-verifies tenant membership; `TenantContext::clear()` in `finally`
3. Runner requires at least one **completed** SyncRun for the source
4. Scan staging records in id order; resolve mapper by provider+entity_type
5. Content path: hash → find/create entity → version on change → provenance link
6. Metric path: deterministic `observation_identity_hash` → upsert
7. Failures stored in `normalization_failures` without raw payloads/secrets

## Mapping

| Provider | Entity | Target | content_type / metric_key |
| --- | --- | --- | --- |
| wordpress | posts | content_entities | post (page only if fixture `type=page`) |
| woocommerce | products | content_entities | product |
| tutor_lms_pro | courses | content_entities | course |
| google_search_console | search_analytics | metric_observations | clicks |
| google_analytics_4 | reports | metric_observations | fixture `metric` field |

Absent fixture fields stay **null**. No cross-provider merge. Metrics stay independent of content entities.

## Idempotency

- Content: unique `(data_source_id, entity_type, external_id_hash)`; new version only when normalized payload hash changes
- Metrics: unique `(data_source_id, observation_identity_hash)`; numeric equality tolerant of decimal scale
- Rebuildable by re-running normalize against the same staging set

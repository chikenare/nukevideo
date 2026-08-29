<?php

namespace App\Http\Controllers\Api;

use App\Data\Analytics\MetricsQueryData;
use App\Enums\MetricDimension;
use App\Enums\MetricShape;
use App\Enums\UsageMetric;
use App\Http\Controllers\Controller;
use App\Services\AnalyticsService;
use App\Services\MetricShaper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The general read over `usage`: whatever breakdown a consumer needs, without a new endpoint each
 * time it needs a different one.
 *
 * This controller is where the authorization lives, and it is the reason a general query endpoint
 * is defensible at all. The dimensions are not equally shareable ({@see MetricDimension} spells out
 * why), so each one states what it requires and this action enforces it before any SQL is built:
 * the operator's fleet dimensions need an operator, the video and viewer-address dimensions need a
 * resolved project and are narrowed to that project's own videos, and the customer-label dimension
 * pins the whole query to the caller's account.
 *
 * What is deliberately NOT enforced is `tracking_id`. `usage` holds no column saying whose an id
 * is, so the only boundary available is that a caller must know an id to name one — the same
 * boundary the dedicated batch endpoint already accepts, stated here rather than left implied.
 */
class MetricsController extends Controller
{
    public function __construct(
        private AnalyticsService $analytics,
        private MetricShaper $shaper,
    ) {}

    public function query(Request $request, MetricsQueryData $data): JsonResponse
    {
        $isAdmin = $request->isAdmin();

        $this->assertDimensionsAllowed($data, $isAdmin);

        $filters = [
            'tracking_id' => $data->list('trackingIds'),
            'external_user_id' => $data->list('externalUserIds'),
            // Narrowed to what the caller's project actually owns, never taken as given. A ULID
            // belonging to someone else drops out silently, exactly as it does on
            // `analytics/videos`: refusing it instead would answer whether that video exists.
            'video_ulid' => $this->ownedVideos($request, $data, $isAdmin),
        ];

        // Asking about videos and getting none of them back means every one named belongs to
        // someone else. Running the query anyway would answer instance-wide, which is the opposite
        // of what the filter was for.
        if ($data->list('videos') !== [] && $filters['video_ulid'] === []) {
            return response()->json(['data' => []]);
        }

        $rows = $this->analytics->query(
            $data->from,
            $data->to,
            $data->dimensions(),
            $filters,
            $data->list('metrics'),
            $this->accountScope($request, $data, $isAdmin),
        );

        return response()->json([
            'data' => $data->shape === MetricShape::WIDE
                ? $this->shaper->wide($rows, $data->dimensions(), $data->from, $data->to)
                : $rows,
        ]);
    }

    /**
     * Refuses a dimension the caller is not entitled to, before anything is read.
     *
     * A 422 and not a 403: the whole request is being rejected on the content of one field, the
     * message has to name which, and the caller's remedy is to send a different query rather than
     * to authenticate differently.
     */
    private function assertDimensionsAllowed(MetricsQueryData $data, bool $isAdmin): void
    {
        $allowed = MetricDimension::allowedFor($isAdmin);

        foreach ($data->dimensions() as $dimension) {
            if (! in_array($dimension->value, $allowed, true)) {
                throw ValidationException::withMessages([
                    'dimensions' => "The [{$dimension->value}] dimension describes the operator's own"
                        .' fleet and is available to administrators only. Available: '.implode(', ', $allowed).'.',
                ]);
            }

            // An operator reads the instance; everyone else has to say what it is asking about,
            // because these two dimensions are identifiers and an unbounded grouping over them
            // enumerates other tenants' videos, viewers and viewer addresses.
            if ($isAdmin) {
                continue;
            }

            if ($dimension->requiresVideoList() && $data->list('videos') === []) {
                throw ValidationException::withMessages([
                    'videos' => "Breaking down by [{$dimension->value}] requires an explicit list of your"
                        .' own videos: it is only readable for titles you own.',
                ]);
            }

            if ($dimension->requiresOwnList() && $data->list('trackingIds') === []) {
                throw ValidationException::withMessages([
                    'tracking_ids' => 'Breaking down by [tracking_id] requires an explicit list of ids:'
                        .' nothing in the usage table says whose an id is, so you may only read the ones you name.',
                ]);
            }
        }
    }

    /**
     * The ULIDs the query may actually read, among those named.
     *
     * For an operator, whatever it named: it reads the instance, so narrowing to one project would
     * answer a smaller question than it asked, and demanding project context would make the fleet
     * dimensions unreachable without picking an arbitrary tenant.
     *
     * For everyone else, the intersection with its own project — which is where
     * `$request->project()` aborts 400, and it aborts exactly when the query needs a project and
     * never when it does not.
     *
     * @return list<string>
     */
    private function ownedVideos(Request $request, MetricsQueryData $data, bool $isAdmin): array
    {
        $named = $data->list('videos');

        if ($isAdmin) {
            return $named;
        }

        $needsProject = $named !== [] || array_filter($data->dimensions(), fn (MetricDimension $d) => $d->requiresProject()) !== [];

        if (! $needsProject) {
            return [];
        }

        return $request->project()->ownedVideoUlids($named);
    }

    /**
     * The account to pin the query to, or null for the instance.
     *
     * Null only for an operator. Everyone else is pinned as soon as the query touches something
     * keyed by account — the customer labels, or the upload and encoding metrics — because those
     * numbers are somebody's in a way delivered bytes are not: `external_user_id` IS the
     * integrator's own customer identifier, and reporting it across accounts hands one tenant
     * another's customers.
     */
    private function accountScope(Request $request, MetricsQueryData $data, bool $isAdmin): ?int
    {
        if ($isAdmin) {
            return null;
        }

        $touchesAccountData = $data->has(MetricDimension::EXTERNAL_USER_ID)
            || $data->list('externalUserIds') !== []
            || array_diff($data->list('metrics'), UsageMetric::delivery()) !== [];

        return $touchesAccountData ? $request->accountId() : null;
    }
}

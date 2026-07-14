<?php

namespace App\Http\Requests;

use App\Models\PlaceList;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /lists/{public_slug} (T-063). The privacy gate lives in authorize() —
 * FormRequest authorization runs BEFORE validation, so a private list is
 * indistinguishable from a missing one (a 404, never a 403 that would leak that
 * the slug exists). Mirrors the T-036 public-profile pattern. The owner may
 * still read their own list through this route even while it is private.
 */
class PublicListShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        $list = $this->route('list');
        if ($list instanceof PlaceList
            && ! $list->is_public
            && $this->user('sanctum')?->id !== $list->user_id) {
            abort(404);
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\StoreApiKeyRequest;
use App\Models\AiProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): Response
    {
        $apiKeyProviders = AiProvider::query()
            ->enabled()
            ->orderBy('name')
            ->with(['userApiKeys' => fn ($query) => $query->where('user_id', $request->user()->id)])
            ->get()
            ->map(fn (AiProvider $provider): array => [
                'key' => $provider->key,
                'name' => $provider->name,
                'has_key' => $provider->userApiKeys->isNotEmpty(),
                'masked_key' => $provider->userApiKeys->first()?->masked(),
            ])
            ->all();

        return Inertia::render('Profile/Edit', [
            'user' => $request->user(),
            'apiKeyProviders' => $apiKeyProviders,
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }

    /**
     * Store (or replace) the user's API key for a provider.
     */
    public function storeApiKey(StoreApiKeyRequest $request): RedirectResponse
    {
        $user = $request->user();

        $provider = AiProvider::where('key', $request->validated('provider'))
            ->where('is_enabled', true)
            ->firstOrFail();

        $user->userApiKeys()->updateOrCreate(
            ['user_id' => $user->id, 'ai_provider_id' => $provider->id],
            ['api_key' => $request->validated('api_key')],
        );

        return Redirect::route('profile.edit')->with('status', 'api-key-saved');
    }

    /**
     * Remove the user's API key for a provider.
     */
    public function destroyApiKey(Request $request, string $providerKey): RedirectResponse
    {
        $user = $request->user();

        $provider = AiProvider::where('key', $providerKey)->firstOrFail();

        $user->userApiKeys()->where('ai_provider_id', $provider->id)->delete();

        return Redirect::route('profile.edit')->with('status', 'api-key-removed');
    }
}

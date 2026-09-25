<?php

namespace App\Http\Controllers;

use App\Classes\AIModelResolver;
use App\Http\Requests\ProfileUpdateRequest;
use App\Http\Requests\StoreApiKeyRequest;
use App\Http\Requests\UpdateAiModelPreferencesRequest;
use App\Http\Requests\UpdateUserSettingsRequest;
use App\Models\AiProvider;
use App\Models\Language;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function __construct(
        protected AIModelResolver $modelResolver,
    ) {}

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

        $languages = Language::query()
            ->enabled()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (Language $language): array => [
                'id' => $language->id,
                'name' => $language->name,
                'native_name' => $language->native_name,
                'is_interface_enabled' => $language->is_interface_enabled,
            ])
            ->all();

        return Inertia::render('Profile/Edit', [
            'user' => $request->user(),
            'apiKeyProviders' => $apiKeyProviders,
            'nativeLanguageId' => $request->user()->settings?->native_language_id,
            'interfaceLanguageId' => $request->user()->settings?->interface_language_id,
            'languages' => $languages,
            'aiModelChoices' => $this->modelResolver->getGroupedModelChoices(),
            'aiModelId' => $request->user()->settings?->ai_model_id,
            'explanationModelId' => $request->user()->settings?->explanation_model_id,
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
     * Update the user's settings (native + interface language).
     */
    public function updateSettings(UpdateUserSettingsRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->settings()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'native_language_id' => $request->validated('native_language_id'),
                'interface_language_id' => $request->validated('interface_language_id'),
            ],
        );

        return Redirect::route('profile.edit', ['tab' => 'preferences'])->with('status', 'settings-updated');
    }

    /**
     * Update the user's AI model preferences (answer + explanation model).
     */
    public function updateAiModels(UpdateAiModelPreferencesRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->settings()->updateOrCreate(
            ['user_id' => $user->id],
            $request->validated(),
        );

        return Redirect::route('profile.edit', ['tab' => 'ai'])->with('status', 'ai-models-updated');
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

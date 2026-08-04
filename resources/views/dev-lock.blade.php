<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />

    <title>{{ config('app.name', 'Laravel') }}</title>

    {{--
        The host app's compiled CSS, or this package's own stylesheet inlined when that cannot be
        resolved. The middleware decides which, so that a missing Vite manifest cannot 500 the one
        page able to unlock this environment. Configured with `statik-dev-lock.dev_vite_entrypoint`.
    --}}
    {!! $styles !!}
</head>

<body class="flex min-h-screen bg-white font-sans text-gray-900 antialiased dark:bg-gray-900 dark:text-gray-100">
<main id="main-content" class="grow">
    <section
        class="flex min-h-screen items-center justify-center bg-linear-to-br from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-900"
    >
        <div id="dev-lock-card" class="mx-auto max-w-80 min-w-80 px-6 py-12">
            <div class="mb-8 text-center">
                <h1 id="dev-access-heading" class="text-4xl font-bold text-gray-900 dark:text-white">
                    {{ __('statik-dev-lock::messages.dev_lock.title') }}
                </h1>
            </div>
            <form
                method="POST"
                action="{{ route('dev.lock.submit', ['redirect_to' => $redirect_to]) }}"
                class="space-y-6"
            >
                @csrf
                <div>
                    <label
                        for="statik_dev_password"
                        class="mb-2 block text-sm font-medium text-gray-700 dark:text-gray-300"
                    >
                        {{ __('statik-dev-lock::messages.dev_lock.enter_password') }}
                    </label>
                    <input
                        type="password"
                        id="statik_dev_password"
                        name="statik_dev_password"
                        required
                        autofocus
                        class="w-full rounded-lg border border-gray-300 px-4 py-3 text-lg focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                        placeholder="••••••"
                    />
                    @if ($error)
                        <p class="mt-1.5 text-sm text-red-600 dark:text-red-400" role="alert">
                            {{ $error }}
                        </p>
                    @endif
                </div>
                <button
                    type="submit"
                    class="w-full items-center justify-center rounded-md border border-transparent bg-indigo-700 px-5 py-3 text-base font-medium text-white transition-colors duration-200 hover:bg-indigo-800 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-hidden"
                >
                    {{ __('statik-dev-lock::messages.dev_lock.access_site') }}
                </button>
            </form>

            <div id="dev-lock-note" class="mt-6 text-center text-sm text-gray-500 dark:text-gray-400">
                <p>{{ __('statik-dev-lock::messages.dev_lock.site_in_dev_mode') }}</p>
                <p>{{ __('statik-dev-lock::messages.dev_lock.api_endpoints_remain') }}</p>
            </div>
        </div>
    </section>
</main>
</body>
</html>

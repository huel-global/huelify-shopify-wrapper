<?php

use Illuminate\Http\Request;

Route::get('oauth', function(Request $request) {
    $shopDomain = $request->get('shop');
    \Log::info('[Huel\Shopify] OAuth request for ['.$shopDomain.']');
    if (!$shopDomain) {
        return abort(403);
    } else {
        if ($request->user() && $request->user()->shop && $request->user()->shop->shop_domain == $shopDomain) {
            $api = app('ShopifyAPI');
            $api->setup([
                'SHOP_DOMAIN' => $shopDomain,
                'ACCESS_TOKEN' => $request->user()->shop->access_token
            ]);

            try {
                $api->call('get',' /admin/shop.json');
                \Log::info('[Huel\Shopify] Test API ' . json_encode($api));
                return redirect()->to('/app');
            } catch (\Exception $ex) {
                \Log::info('ERROR ' . json_encode($ex));
            }
        }

        $api = app('ShopifyAPI');
        $api->setup([
            'SHOP_DOMAIN' => $shopDomain
        ]);
        $easdk = app('ShopifyEASDK');
        $easdk->setAPI($api);

        echo $easdk->hostedRedirect($shopDomain, $api->installURL(\URL::to('/oauth/done/'), \Config::get('huel_shopify.scopes')));
        exit;
    }
})->middleware('web');

Route::get('oauth/done', function(Request $request) {
    $shopDomain = $request->get('shop');
    $api = app('ShopifyAPI');
    $api->setup([
        'SHOP_DOMAIN' => $shopDomain
    ]);

    if (!$api->verifyRequest($request->all())) {
        \Log::info('[Huel\Shopify] OAuth request for ['.$shopDomain.'] could not be verified.');
        return redirect()->to('/oauth?shop='.$shopDomain);
    }

    try {
        $accessToken = $api->getAccessToken($request->get('code'));
        $api->setup([
            'ACCESS_TOKEN' => $accessToken
        ]);
    } catch (\Exception $ex) {
        \Log::info('[Huel\Shopify] OAuth request for ['.$shopDomain.'] failed - retrying.');
        return redirect()->to('/oauth/?shop='.$shopDomain);
    }

    $shop = \App\Shop::findByDomain($shopDomain);

    if (!$shop) {
        \Log::info('[Huel\Shopify] OAuth request for ['.$shopDomain.'] successful - creating account.');
        $user = new \App\User;
        $user->name = $shopDomain;
        $user->password = '';
        $user->email = 'owner@'.$shopDomain;
        $user->save();

        $shop = new \App\Shop;
        $shop->shop_domain = $shopDomain;
        $shop->user_id = $user->id;
        $shop->access_token = $accessToken;
        $shop->save();
    }

    \Log::info('[Huel\Shopify] OAuth request for ['.$shopDomain.'] successful - logging in.');
    $shop->access_token = $accessToken;
    $shop->save();

    $shop->login();

    if (count(\Config::get('huel_shopify.webhooks')) > 0) {
        \Log::info('[Huel\Shopify] OAuth request for ['.$shopDomain.'] successful - setting up webhooks.');

        foreach (\Config::get('huel_shopify.webhooks') as $hook) {
            if (count($api->call('get', '/admin/webhooks.json', ['topic' => $hook['topic'], 'address' => $hook['address']])->webhooks) == 0) {
                $api->call('post', '/admin/webhooks.json', [
                    'webhook' => $hook
                ]);
            }
        }
    }

    $api = $shop->getAPI();

    if (count(\Config::get('huel_shopify.script_tags')) > 0) {
        \Log::info('[Huel\Shopify] OAuth request for ['.$shopDomain.'] successful - setting up scripttags.');

        foreach (\Config::get('huel_shopify.script_tags') as $url) {
            if (count($api->call('get', '/admin/script_tags.json', ['src' => $url])->script_tags) == 0) {
                $api->call('post', '/admin/script_tags.json', [
                    'script_tag' => [
                        'event' => 'onload',
                        'src' => $url
                    ]
                ]);
            }
        }
    }
    //redirect to custom url passed in as a cookie via a request or redirect
    if ($request->hasCookie('redirect_url')) {
        return redirect(
            $request->cookie('redirect_url')
        )->cookie(Cookie::forget('redirect_url'));
    }

    return redirect()->intended('/app/');
})->middleware('web');

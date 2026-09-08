# Turnstile — Kanboard 登入表單的 Cloudflare 人機驗證

[English](README.md) · **繁體中文** · [日本語](README.ja.md)

在登入按鈕上方放一個 [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/)
驗證，並且在**看帳號密碼之前**先在伺服器端驗過。

Kanboard 本身有內建 CAPTCHA，但它要連續登入失敗幾次才出現，而且是 GD 畫出來的圖片，
OCR 讀起來不費力。Turnstile 則是每次登入都跑，對大多數訪客是無感的，而且是拿去問
Cloudflare，不是比對 session 裡的某個值。

不改任何核心檔案、不覆寫任何模板，也不需要搭配佈景主題——在原生的 Kanboard 上就是對的。

---

## 安裝

Kanboard 的外掛就是 `plugins/` 底下的一個目錄，而目錄名稱就是外掛的命名空間，所以不管
用哪種方式，它都必須落在 `plugins/Turnstile`。

**用 git：**

```
cd /path/to/kanboard/plugins
git clone https://github.com/kaiyichen0421/kanboard-cloudflare.git Turnstile
```

**用 zip**——給沒有 shell 的主機：

```
unzip Turnstile-x.y.z.zip -d /path/to/kanboard/plugins
```

壓縮檔裡已經含有 `Turnstile/` 這層目錄，所以直接解到 `plugins/` 就好。同一個檔案也可以在
有開 `PLUGIN_INSTALLER` 的站台用**設定 → 外掛 → 從網址安裝**。

裝完重新整理頁面即可。沒有快取要清、沒有 migration 要跑——這個外掛不建任何資料表，只在
Kanboard 自己的 `settings` 寫兩列。

要移除就把目錄刪掉。它留下的就只有那兩列，沒有外掛的話它們不會有任何作用。

### 需求

Kanboard 1.2.0 以上，以及伺服器能對外連到 `challenges.cloudflare.com`——驗證是
server-to-server 的呼叫，所以有出口防火牆的站台要把這個主機放行，否則每次登入都會被擋下來。

---

## 設定

**1. 建立 widget。** 在 Cloudflare 後台選 **Turnstile → Add widget**，把登入頁會用到的
每一個主機名稱都列進去——不在名單上的一律回錯誤 `110200`，而且那份名單只吃主機名稱、
不吃 IP，所以 `127.0.0.1` 永遠不可能被授權。本機開發環境請加 `localhost`。

**2. 貼上金鑰。** **設定 → Turnstile 人機驗證**（限管理員）。填 site key 跟 secret key，
按**儲存**。

**3. 先確認再信任。** **測試連線**會直接問 Cloudflare 這組 secret 能不能用，完全不會碰到
登入表單：

| 回應 | 意思 |
|---|---|
| Secret key 可以正常使用。 | Cloudflare 認得這組 secret。 |
| Cloudflare 拒絕了這組 secret key。 | secret 填錯，或者那個 widget 已經被刪掉了。 |
| 無法連線到 Cloudflare：… | 伺服器連不到 `challenges.cloudflare.com`。 |

`siteverify` 沒有 ping 之類的端點，所以這裡是故意送一個無效的 token 過去：secret 是好的
會回 `invalid-input-response`，secret 是壞的會回 `invalid-input-secret`。重點就是把這兩種
失敗分開——「連不到」跟「被拒絕」要修的東西不一樣。

**兩欄都留空就是停用。** widget 不會被畫出來、validator 會直接放行、
Content-Security-Policy 也不會被放寬。剛裝好的狀態就是這樣，所以光是安裝這個外掛，
不可能把任何人鎖在門外。

### 萬一真的把自己鎖在外面

這個檢查的設計是 fail closed：金鑰一旦設定，只要 Cloudflare 沒有明確說通過，這次登入就
不算數。所以 widget 被刪掉、金鑰貼的時候多一個空格、或是主機名稱沒有加進名單，都會讓
**所有人**進不去，管理員也一樣。

回去的路就是把那兩列清掉：

```sql
DELETE FROM settings WHERE option IN ('turnstile_site_key', 'turnstile_secret_key');
```

手邊沒有資料庫工具的話，把 `plugins/Turnstile/` 刪掉也一樣——下一個 request 就會回到
原本的登入表單。

至於是哪一種失敗，瀏覽器會告訴你。Cloudflare 自己只會畫一個「Troubleshoot」連結，那什麼
也沒說，所以這個外掛會把錯誤碼印在 widget 下面：`110200` 是主機名稱不在 widget 的名單裡、
`400xxx` 是 site key 無效、`300xxx` 是驗證執行失敗、`600xxx` 是驗證逾時。

---

## 運作方式

### 那道閘門

驗證一定要比帳號密碼早檢查，而 Kanboard 為此只跑一條鏈：`AuthValidator::validateForm()`。
`Plugin.php` 把容器裡的 `authValidator` 換成一個子類別，在那條鏈中間多插一步：

```php
array('validateFields', 'validateLocking', 'validateCaptcha', 'validateTurnstile', 'validateCredentials')
```

位置決定了兩件事。沒通過驗證的請求根本走不到密碼檢查，所以繞過 widget 直接 POST 到
`/login/check` 會被擋下來——這個 token 不是可有可無的欄位，它是一個 validator。另外因為它
排在 `validateLocking` **之後**、`validateCredentials` **之前**，驗證失敗既不會累積到
Kanboard 的暴力破解鎖定，也不會把它重置。

只要不是 Cloudflare 明確回 `"success": true`，這次登入就是不通過，包含那種根本送不出去的
請求。使用者只會看到一句「人機驗證失敗，請再試一次。」，真正的原因連同 Cloudflare 的錯誤碼
會進 Kanboard 的 log，而不是丟到瀏覽器上。

### widget 放在哪裡

Kanboard 的登入模板只給兩個 hook：一個在整個表單上方，一個在 `</form>` 之後。兩個都不在
表單裡面——而畫在表單外面的 widget，它自己產生的 token 欄位也會在表單外面，瀏覽器永遠不會
把它送出去。

所以這個外掛只在第二個 hook 裡放一個空的佔位元素，再由 `Assets/js/turnstile.js` 把它搬進
表單的 actions 區塊、當第一個子元素——也就是登入按鈕的正上方。Turnstile 接著就在表單裡面
把自己畫出來，它的 token 欄位跟帳號、密碼一起被送出，跟任何一個 input 沒兩樣。不必複製任何
值，也不用維護什麼隱藏欄位。

之所以是搬「進」actions 區塊而不是放在它前面：主題本來就可以用 flexbox 加明確的 `order`
來排這個表單——Kanboard 的原始標記也沒給別的辦法把「記住我」跟「忘記密碼」排成一行——而
「別人的 order 是多少」正是這個外掛猜不到的東西。待在裝著按鈕的那個區塊裡，按鈕跑到哪，
widget 就跟到哪。

### 為什麼 api.js 是由腳本載入，而不是寫在模板裡

widget 是用 explicit render 畫出來的，而 `api.js` 是外掛自己的腳本去要的，不是模板裡的
`<script>` 標籤——這樣載入順序是被決定的，不是靠運氣的。

一般的作法是在元素上掛 `cf-turnstile`、用 `data-callback` 寫下 callback 的名字，但那些名字
是在 `api.js` 執行的當下才去 `window` 上找的。Kanboard 載入外掛腳本用的是 `defer`，而
`api.js` 會是 `async`——`api.js` 有機會先到，找不到 callback，然後靜靜地把它丟掉。症狀是最
難查的那一種：widget 明明顯示 **成功!**，登入卻因為「沒有 token」被拒絕。等這個腳本跑完
才去載 `api.js`，並且直接把真正的函式傳給 `render()`，這種事就不可能發生。

至於為什麼是獨立檔案而不是 inline，也不是偏好問題：Kanboard 送的是 `default-src 'self'`，
inline script 會被拒絕。

### Content-Security-Policy

Kanboard 預設的政策不允許任何外部來源，所以 `challenges.cloudflare.com` 會被加進
`script-src` 和 `frame-src`——但只有在真的存了 site key 之後才加。裝了卻沒設定的外掛，
不會動到原本的政策一個字。

### 淺色與深色

widget 用 `theme: 'auto'` 畫出來，跟著作業系統走。Kanboard 自己的淺色/深色是每個使用者
各自的設定，而登入頁正好是唯一還沒有使用者的畫面，所以沒有別的東西可以跟。

---

## 翻譯

原文是英文；繁體中文（`zh_TW`）和日文（`ja_JP`）放在 `Locale/`。其他語言會退回英文，要新增
一種語言就是加一個 `Locale/<code>/translations.php`。

---

## 授權

MIT，見 [LICENSE](LICENSE)。

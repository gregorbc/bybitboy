# Tetris APK Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a signed, installable Android APK that bundles the existing Tetris web game and runs it offline in a WebView.

**Architecture:** A minimal native Android project (`android/`) with no external dependencies beyond the Android SDK. `MainActivity` hosts a `WebView` loading `index.html` from app assets. All 4 game files (index.html, style.css, tetris.js, logic.js) are copied into `app/src/main/assets/`. The release APK is signed with a project-local keystore.

**Tech Stack:** Java 21 (JDK), Android Gradle Plugin 8.2.1, Gradle 8.8, compileSdk 34, minSdk 24, build-tools 34.0.0, apksigner.

## Global Constraints

- Package/applicationId: `com.gregorbritez.tetris`
- App label: `Tetris`
- `compileSdk 34`, `targetSdk 34`, `minSdk 24`
- No AndroidX, no third-party dependencies — plain `android.app.Activity` + `WebView`
- Keystore and `keystore.properties` must NEVER be committed to git
- All build work happens in `public_html/tetris/android/`
- ANDROID_HOME is `/opt/android-sdk`; cached Gradle binary:
  `/root/.gradle/wrapper/dists/gradle-8.8-all/6gdy1pgp427xkqcjbxw3ylt6h/gradle-8.8/bin/gradle`
  (referred to below as `GRADLE`)
- Internet is available (Maven Central, dl.google.com, services.gradle.org)

---

### Task 1: Scaffold Android project and verify it compiles

**Files:**
- Create: `android/settings.gradle`
- Create: `android/build.gradle`
- Create: `android/gradle.properties`
- Create: `android/local.properties`
- Create: `android/.gitignore`
- Create: `android/app/build.gradle`
- Create: `android/app/src/main/AndroidManifest.xml`
- Create: `android/app/src/main/java/com/gregorbritez/tetris/MainActivity.java`
- Create: `android/app/src/main/assets/` (copy of `index.html`, `style.css`, `tetris.js`, `logic.js`)
- Create (via gradle): `android/gradlew`, `android/gradle/wrapper/*`
- Test: `./gradlew assembleDebug` succeeds

**Interfaces:**
- Consumes: nothing (first task)
- Produces: a compilable project scaffold. Task 3 relies on the release buildType and signingConfig defined in `app/build.gradle`.

- [ ] **Step 1: Create directories**

```bash
mkdir -p android/app/src/main/java/com/gregorbritez/tetris android/app/src/main/assets android/keystore
cp index.html style.css tetris.js logic.js android/app/src/main/assets/
```

- [ ] **Step 2: Write `android/settings.gradle`**

```groovy
pluginManagement {
    repositories {
        google()
        mavenCentral()
        gradlePluginPortal()
    }
}
dependencyResolutionManagement {
    repositoriesMode.set(RepositoriesMode.FAIL_ON_PROJECT_REPOS)
    repositories {
        google()
        mavenCentral()
    }
}
rootProject.name = 'tetris-android'
include ':app'
```

- [ ] **Step 3: Write `android/build.gradle`**

```groovy
plugins {
    id 'com.android.application' version '8.2.1' apply false
}
```

- [ ] **Step 4: Write `android/gradle.properties`**

```properties
org.gradle.jvmargs=-Xmx2g -Dfile.encoding=UTF-8
android.nonTransitiveRClass=true
```

- [ ] **Step 5: Write `android/local.properties`**

```properties
sdk.dir=/opt/android-sdk
```

- [ ] **Step 6: Write `android/.gitignore`**

```gitignore
.gradle/
build/
local.properties
keystore/
keystore.properties
app/gradle.properties
```

- [ ] **Step 7: Write `android/app/build.gradle`**

```groovy
plugins {
    id 'com.android.application'
}

android {
    namespace 'com.gregorbritez.tetris'
    compileSdk 34

    defaultConfig {
        applicationId 'com.gregorbritez.tetris'
        minSdk 24
        targetSdk 34
        versionCode 1
        versionName '1.0'
    }

    signingConfigs {
        release {
            def props = new Properties()
            def f = file('../keystore.properties')
            if (f.exists()) {
                props.load(new FileInputStream(f))
            }
            storeFile file(props.getProperty('STORE_FILE', 'missing.keystore'))
            storePassword props.getProperty('STORE_PASSWORD', '')
            keyAlias props.getProperty('KEY_ALIAS', '')
            keyPassword props.getProperty('KEY_PASSWORD', '')
        }
    }

    buildTypes {
        release {
            signingConfig signingConfigs.release
            minifyEnabled false
        }
    }
}
```

- [ ] **Step 8: Write `android/app/src/main/AndroidManifest.xml`**

```xml
<?xml version="1.0" encoding="utf-8"?>
<manifest xmlns:android="http://schemas.android.com/apk/res/android">

    <application
        android:label="Tetris"
        android:icon="@android:drawable/sym_def_app_icon"
        android:theme="@android:style/Theme.Black.NoTitleBar.Fullscreen">
        <activity
            android:name=".MainActivity"
            android:exported="true"
            android:screenOrientation="portrait"
            android:configChanges="orientation|screenSize|keyboardHidden">
            <intent-filter>
                <action android:name="android.intent.action.MAIN" />
                <category android:name="android.intent.category.LAUNCHER" />
            </intent-filter>
        </activity>
    </application>

</manifest>
```

- [ ] **Step 9: Write `android/app/src/main/java/com/gregorbritez/tetris/MainActivity.java`**

```java
package com.gregorbritez.tetris;

import android.annotation.SuppressLint;
import android.app.Activity;
import android.os.Build;
import android.os.Bundle;
import android.view.View;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

public class MainActivity extends Activity {

    private WebView webView;

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        webView = new WebView(this);
        webView.setWebViewClient(new WebViewClient());
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);

        webView.loadUrl("file:///android_asset/index.html");
        setContentView(webView);

        hideSystemUi();
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus) {
            hideSystemUi();
        }
    }

    @Override
    public void onBackPressed() {
        moveTaskToBack(true);
    }

    private void hideSystemUi() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.KITKAT) {
            webView.setSystemUiVisibility(
                View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                | View.SYSTEM_UI_FLAG_FULLSCREEN
                | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION);
        }
    }
}
```

- [ ] **Step 10: Generate the Gradle wrapper**

Run (from `public_html/tetris/android`):
```bash
GRADLE=/root/.gradle/wrapper/dists/gradle-8.8-all/6gdy1pgp427xkqcjbxw3ylt6h/gradle-8.8/bin/gradle
"$GRADLE" wrapper --gradle-version 8.8
```
Expected: creates `gradlew`, `gradlew.bat`, `gradle/wrapper/gradle-wrapper.jar` and `gradle-wrapper.properties`.

- [ ] **Step 11: Verify debug build compiles**

Run (from `public_html/tetris/android`):
```bash
./gradlew assembleDebug --no-daemon
```
Expected: `BUILD SUCCESSFUL`, APK at `app/build/outputs/apk/debug/app-debug.apk`.
Note: this first run downloads AGP + AndroidX-less deps (a few minutes).

- [ ] **Step 12: Commit**

```bash
git add android/
git commit -m "feat(tetris): scaffold Android WebView app for the game"
```

---

### Task 2: Create release keystore and signing config

**Files:**
- Create: `android/keystore/release.keystore`
- Create: `android/keystore.properties`
- Verify: `android/app/build.gradle` release signingConfig reads it

**Interfaces:**
- Consumes: Task 1 scaffold (signingConfig block already in `app/build.gradle`)
- Produces: `android/keystore.properties` consumed by the signingConfig in Task 3's release build. Password values live only in this file (gitignored).

- [ ] **Step 1: Generate the keystore**

Run (from `public_html/tetris/android`). Generate a random password first and save it to use consistently:

```bash
PASS=$(openssl rand -base64 18 | tr '+/' 'Aq')
keytool -genkeypair -v \
  -keystore keystore/release.keystore \
  -alias tetris \
  -keyalg RSA -keysize 2048 -validity 10000 \
  -storepass "$PASS" -keypass "$PASS" \
  -dname "CN=Gregor Britez, OU=Tetris, O=Gregor Britez, C=PY"
```

- [ ] **Step 2: Write `android/keystore.properties`**

Use the same `$PASS` from Step 1:

```properties
STORE_FILE=../keystore/release.keystore
STORE_PASSWORD=<PASS>
KEY_ALIAS=tetris
KEY_PASSWORD=<PASS>
```

- [ ] **Step 3: Verify the keystore exists and is readable**

Run (from `public_html/tetris/android`):
```bash
keytool -list -keystore keystore/release.keystore -storepass "$PASS"
```
Expected: shows one entry with alias `tetris` (PrivateKeyEntry).

- [ ] **Step 4: Verify .gitignore protects secrets**

Run:
```bash
git check-ignore android/keystore/release.keystore android/keystore.properties
```
Expected: both paths printed (ignored).

- [ ] **Step 5: Commit**

```bash
git add android/.gitignore
git commit -m "chore(tetris): ignore release keystore and signing credentials"
```

---

### Task 3: Build the signed release APK and verify

**Files:**
- Test/artifact: `android/app/build/outputs/apk/release/app-release.apk`

**Interfaces:**
- Consumes: Task 1 scaffold + Task 2 keystore/credentials
- Produces: final signed APK + verification evidence

- [ ] **Step 1: Build the release APK**

Run (from `public_html/tetris/android`):
```bash
./gradlew assembleRelease --no-daemon
```
Expected: `BUILD SUCCESSFUL`, `app/build/outputs/apk/release/app-release.apk` created.

- [ ] **Step 2: Verify signature with apksigner**

Run:
```bash
/opt/android-sdk/build-tools/34.0.0/apksigner verify --print-certs app/build/outputs/apk/release/app-release.apk
```
Expected: `Verified using v1 scheme` / `v2 scheme` and the RSA cert DN `CN=Gregor Britez, ...`.

- [ ] **Step 3: Verify the 4 game assets are inside the APK**

Run:
```bash
unzip -l app/build/outputs/apk/release/app-release.apk | grep 'assets/'
```
Expected: `assets/index.html`, `assets/style.css`, `assets/tetris.js`, `assets/logic.js` present.

- [ ] **Step 4: Verify package and launcher activity**

Run:
```bash
/opt/android-sdk/build-tools/34.0.0/aapt dump badging app/build/outputs/apk/release/app-release.apk | head -5
```
Expected: `package: name='com.gregorbritez.tetris'`, `application-label:'Tetris'`, launchable-activity `.MainActivity`.

- [ ] **Step 5: Copy final APK to a convenient location**

Run (from `public_html/tetris`):
```bash
mkdir -p apk
cp android/app/build/outputs/apk/release/app-release.apk apk/tetris-1.0.apk
```

- [ ] **Step 6: Commit**

```bash
git add apk/.gitignore   # ignore *.apk binary
git commit -m "chore(tetris): ignore built APK artifacts"
```

---

### Task 4: Final verification pass

- [ ] **Step 1: Rebuild from clean to prove reproducibility**

Run (from `public_html/tetris/android`):
```bash
./gradlew clean assembleRelease --no-daemon
```
Expected: `BUILD SUCCESSFUL`.

- [ ] **Step 2: Re-verify signature and assets on the fresh APK**

Run:
```bash
/opt/android-sdk/build-tools/34.0.0/apksigner verify --print-certs app/build/outputs/apk/release/app-release.apk
unzip -l app/build/outputs/apk/release/app-release.apk | grep 'assets/'
```
Expected: valid signature; the 4 assets present.

- [ ] **Step 3: Confirm no secrets are tracked in git**

Run (from `public_html` root):
```bash
git status --porcelain | grep -E 'keystore|\.properties' || echo "no secrets tracked"
git ls-files | grep -E 'keystore' || echo "keystore not in index"
```
Expected: `no secrets tracked` and `keystore not in index`.

- [ ] **Step 4: Report final artifact path**

Confirm the deliverable: `public_html/tetris/apk/tetris-1.0.apk` (signed release, installable on Android 7.0+).

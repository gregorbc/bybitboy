# Tetris APK para Android — Diseño

Fecha: 2026-08-04

## Objetivo

Empaquetar el juego Tetris (HTML/CSS/JS puro, ubicado en `tetris/`) como una app
Android instalable, 100% offline, sin dependencias externas.

## Enfoque

Proyecto Android nativo mínimo con un WebView que carga los archivos del juego
desde los assets de la aplicación. Sin frameworks (sin Capacitor, sin Cordova),
sin PWA/TWA. El juego ya es responsive y tiene controles táctiles, por lo que
solo necesita un contenedor WebView.

## Estructura

```
tetris/android/
├── build.gradle                  # config raíz
├── settings.gradle               # incluye :app
├── gradle.properties             # AndroidX + memoria
├── gradlew / gradle/wrapper/     # Gradle wrapper
├── keystore/                     # keystore de release (firma)
└── app/
    ├── build.gradle              # minSdk 24, target 34, firma release
    └── src/main/
        ├── AndroidManifest.xml   # portrait, fullscreen, paquete com.gregorbritez.tetris
        ├── java/com/gregorbritez/tetris/MainActivity.java
        └── assets/               # copia de index.html, style.css, tetris.js, logic.js
```

## Componentes

### MainActivity.java

- WebView que carga `file:///android_asset/index.html`.
- `setJavaScriptEnabled(true)` y `DOM storage` habilitados.
- Modo inmersivo (oculta barras de sistema).
- `onBackPressed`: no sale de la app desde la primera pulsación mientras se
  juega; se minimiza a segundo plano.
- Orientación portrait.

### Assets

Los 4 archivos del juego (`index.html`, `style.css`, `tetris.js`, `logic.js`) se
copian a `app/src/main/assets/`. Los enlaces relativos del HTML funcionan tal cual.

### Configuración de la app

| Propiedad        | Valor                            |
|------------------|----------------------------------|
| Nombre visible   | Tetris                           |
| Paquete          | com.gregorbritez.tetris          |
| minSdkVersion    | 24 (Android 7.0)                 |
| targetSdkVersion | 34                               |
| Firma            | keystore de release propio       |

## Firma

- Se genera un keystore de release (`keystore/release.keystore`) con `keytool`.
- Las credenciales (contraseña, alias) se guardan en `keystore.properties`
  (local, excluido de git) y se referencian desde `app/build.gradle` vía
  `signingConfigs.release`.
- El keystore NO se versiona en git; se documenta en `.gitignore`.

## Build y verificación

1. `./gradlew assembleRelease` con `ANDROID_HOME=/opt/android-sdk`.
2. APK final en `app/build/outputs/apk/release/app-release.apk`.
3. `apksigner verify` confirma firma válida.
4. Comprobación de que los 4 assets están dentro del APK.

## Alcance (YAGNI)

Fuera de alcance: splash personalizada, icono custom, guardado de puntuaciones,
tienda Google Play. Solo el juego actual funcionando como app instalable.

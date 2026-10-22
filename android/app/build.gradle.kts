plugins { id("com.android.application"); id("org.jetbrains.kotlin.android"); id("org.jetbrains.kotlin.plugin.compose") }
fun setting(name: String, default: String = "") = providers.gradleProperty(name).orElse(providers.environmentVariable(name)).getOrElse(default)
fun quoted(value: String) = "\"" + value.replace("\\", "\\\\").replace("\"", "\\\"") + "\""
android {
 namespace = "org.cdrmapp.citizen"
 compileSdk = 36
 defaultConfig {
  applicationId = "org.cdrmapp.citizen"; minSdk = 26; targetSdk = 36; versionCode = 1; versionName = "0.1.0"
  buildConfigField("String", "API_BASE_URL", quoted(setting("CDRMAPP_API_URL", "https://example.invalid/api/v1/")))
  buildConfigField("String", "MAPS_KEY", quoted(setting("GOOGLE_MAPS_ANDROID_KEY")))
  buildConfigField("String", "FIREBASE_APPLICATION_ID", quoted(setting("FIREBASE_APPLICATION_ID")))
  buildConfigField("String", "FIREBASE_PROJECT_ID", quoted(setting("FIREBASE_PROJECT_ID")))
  buildConfigField("String", "FIREBASE_API_KEY", quoted(setting("FIREBASE_API_KEY")))
  buildConfigField("String", "FIREBASE_SENDER_ID", quoted(setting("FIREBASE_SENDER_ID")))
  manifestPlaceholders["MAPS_API_KEY"] = setting("GOOGLE_MAPS_ANDROID_KEY")
  testInstrumentationRunner = "androidx.test.runner.AndroidJUnitRunner"
 }
 buildTypes { release { isMinifyEnabled = false }; debug { manifestPlaceholders["cleartextAllowed"] = "true" }; release { manifestPlaceholders["cleartextAllowed"] = "false" } }
 compileOptions { sourceCompatibility = JavaVersion.VERSION_17; targetCompatibility = JavaVersion.VERSION_17 }
 buildFeatures { compose = true; buildConfig = true }
 lint { abortOnError = true }
}
kotlin { jvmToolchain(21); compilerOptions { jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17) } }
dependencyLocking { lockAllConfigurations() }
dependencies {
 implementation(platform("androidx.compose:compose-bom:2025.10.01"))
 implementation("androidx.activity:activity-compose:1.11.0")
 implementation("androidx.compose.material3:material3")
 implementation("androidx.compose.ui:ui-tooling-preview")
 implementation("androidx.lifecycle:lifecycle-viewmodel-compose:2.9.4")
 implementation("androidx.lifecycle:lifecycle-runtime-compose:2.9.4")
 implementation("com.google.maps.android:maps-compose:6.12.0")
 implementation("com.google.android.gms:play-services-location:21.3.0")
 implementation("com.google.firebase:firebase-messaging:26.0.1")
 implementation("com.squareup.retrofit2:retrofit:3.0.0")
 implementation("com.squareup.retrofit2:converter-gson:3.0.0")
 implementation("org.jetbrains.kotlinx:kotlinx-coroutines-android:1.10.2")
 testImplementation("junit:junit:4.13.2")
 testImplementation("com.squareup.okhttp3:mockwebserver:4.12.0")
 androidTestImplementation("androidx.test.ext:junit:1.3.0")
 androidTestImplementation("androidx.test.espresso:espresso-core:3.7.0")
 androidTestImplementation(platform("androidx.compose:compose-bom:2025.10.01"))
 androidTestImplementation("androidx.compose.ui:ui-test-junit4")
 debugImplementation("androidx.compose.ui:ui-tooling")
 debugImplementation("androidx.compose.ui:ui-test-manifest")
}

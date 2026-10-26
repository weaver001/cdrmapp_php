package org.cdrmapp.citizen.data
import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import android.util.Log
import org.cdrmapp.citizen.BuildConfig
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/** AES/GCM key remains in Android Keystore; backups are disabled in the manifest. */
interface SecretStorage { fun put(name: String, value: String?); fun get(name: String): String? }
class SecureStore(context: Context) : SecretStorage {
 private companion object { val keyLock = Any() }
 private val preferences = context.getSharedPreferences("encrypted_session", Context.MODE_PRIVATE)
 private val alias = "cdrmapp_session_v1"
 private fun key(): SecretKey = synchronized(keyLock) {
  val store = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
  (store.getKey(alias, null) as? SecretKey)?.let { return@synchronized it }
  KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore").apply {
   init(KeyGenParameterSpec.Builder(alias, KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT).setBlockModes(KeyProperties.BLOCK_MODE_GCM).setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE).build())
  }.generateKey()
 }
 override fun put(name: String, value: String?) {
  if (value == null) { check(preferences.edit().remove(name).commit()); return }
  val cipher = Cipher.getInstance("AES/GCM/NoPadding").apply { init(Cipher.ENCRYPT_MODE, key()) }
  val encrypted = cipher.doFinal(value.toByteArray(Charsets.UTF_8))
  val encoded = Base64.encodeToString(cipher.iv + encrypted, Base64.NO_WRAP)
  check(preferences.edit().putString(name, encoded).commit()) { "Could not persist secure data." }
 }
 override fun get(name: String): String? {
  val value = preferences.getString(name, null) ?: return null
  return try {
   val bytes = Base64.decode(value, Base64.NO_WRAP)
   Cipher.getInstance("AES/GCM/NoPadding").run { init(Cipher.DECRYPT_MODE, key(), GCMParameterSpec(128, bytes.copyOfRange(0, 12))); String(doFinal(bytes.copyOfRange(12, bytes.size)), Charsets.UTF_8) }
  } catch (_: Exception) {
   if (BuildConfig.DEBUG) Log.w("CDRMAPP_AUTH", "Encrypted storage read failed; signing in again may be required.")
   preferences.edit().remove(name).apply(); null
  }
 }
}

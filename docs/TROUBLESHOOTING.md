# Troubleshooting Guide

## Talk AI v2.0 - Multi-Bot System

### Bot Not Responding in Talk

**Most Common Issue: Wrong Webhook URL**

Talk bot webhooks **must** include `/index.php` in the path.

**Check error count:**
```bash
sudo -u www-data php occ talk:bot:list
```

If `error_count` > 0, the webhook URL is likely wrong.

**Fix:**
```bash
# Uninstall
sudo -u www-data php occ talk:bot:uninstall "Talk AI"

# Reinstall with CORRECT URL (note /index.php)
sudo -u www-data php occ talk:bot:install \
  -f webhook,response \
  "Talk AI" \
  "your-webhook-secret" \
  "https://your-domain/index.php/apps/educai/webhook/talk"
```

**URL Format:**
- ❌ `https://domain/apps/educai/webhook/talk` - Results in 405 Error
- ✅ `https://domain/index.php/apps/educai/webhook/talk` - Works!

### Webhook Debugging

**Enable detailed logging:**
```bash
sudo -u www-data php occ log:manage --level info
```

**Watch logs in real-time:**
```bash
tail -f /path/to/nextcloud/data/nextcloud.log | grep -i educai
```

**Test the bot:**
```
@yourbotname test message
```

**Expected log output:**
```
========== Talk AI Webhook Received ==========
Bot detected
Calling LLM API
Got LLM response
Bot response sent successfully
========== Webhook Processing Complete ==========
```

### LLM Connection Issues

**Symptom:** Bot responds with error message about AI service

**Check:**
1. API Endpoint is reachable
2. API Key is valid
3. Model name is correct
4. Network connectivity from Nextcloud server

**Test LLM directly:**
```bash
curl https://your-api-endpoint/v1/chat/completions \
  -H "Authorization: Bearer your-key" \
  -H "Content-Type: application/json" \
  -d '{"model":"your-model","messages":[{"role":"user","content":"test"}]}'
```

---

## Debug Mode

Enable debug mode for more detailed logging:

**In `config/config.php`:**
```php
'debug' => true,
'loglevel' => 0,  // 0 = debug, 1 = info, 2 = warn, 3 = error
```

**Remember to disable after debugging:**
```php
'debug' => false,
'loglevel' => 2,
```

## Still Having Issues?

1. **Check Nextcloud version compatibility:**
   - App requires Nextcloud 30-32
   - Run: `./occ status` to check version

2. **Verify PHP version:**
   - Requires PHP 8.1+
   - Run: `php -v`

3. **Check file permissions:**
   ```bash
   # All files should be readable by web server
   chown -R www-data:www-data /path/to/nextcloud/apps-extra/educai
   ```

4. **Report bug:**
   - Visit: https://github.com/EDUCAlliance/Nextcloud-EDUC-AI-Plugin/issues
   - Include: Nextcloud version, PHP version, browser console errors

---

**Need more help?** Open an issue: https://github.com/EDUCAlliance/Nextcloud-EDUC-AI-Plugin/issues


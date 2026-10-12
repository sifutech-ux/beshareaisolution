import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";

const source = readFileSync(new URL("../../whatsapp/ruang.js", import.meta.url), "utf8");
const start = source.indexOf("function facebookHost");
const end = source.indexOf("function openEmbeddedSignup");
if (start < 0 || end < start) {
  console.log("FAIL signup helpers are present");
  process.exit(1);
}
const context = createContext({ URL, URLSearchParams });
runInContext(source.slice(start, end), context);

let failures = 0;
const check = (name, ok) => {
  console.log((ok ? "OK " : "FAIL ") + name);
  if (!ok) failures += 1;
};

const reported = context.signupDiagnostic({
  error_message: "Your verified name violates WhatsApp guidelines.",
  error_code: "524126",
  error_id: "LEGACY",
  session_id: "f34b51dab5e0498"
});
check("documented cancel prefers error_code", reported.error_code === "524126" && reported.session_id === "f34b51dab5e0498");
check("diagnostic result has no message field", !Object.hasOwn(reported, "error_message"));

const numeric = context.signupDiagnostic({ error_code: 524126, session_id: "bad id" });
check("numeric error code is kept and an unsafe session id is dropped", numeric.error_code === "524126" && numeric.session_id === "");

const legacy = context.signupDiagnostic({ error_id: "ERR_LEGACY" });
check("legacy error id remains a fallback", legacy.error_code === "ERR_LEGACY" && legacy.session_id === "");

const fields = context.signupEventFields({
  type: "WA_EMBEDDED_SIGNUP",
  event: "CANCEL",
  data: {
    error_code: 524126,
    current_step: "PHONE_NUMBER_SETUP",
    waba_id: 100000000000101,
    phone_number_id: "100000000000202",
    error_message: "do not keep"
  }
});
check(
  "cancel event fields keep ids and omit the message",
  fields.event === "CANCEL"
    && fields.error_code === "524126"
    && fields.current_step === "PHONE_NUMBER_SETUP"
    && fields.waba_id === "100000000000101"
    && fields.phone_number_id === "100000000000202"
    && !Object.hasOwn(fields, "error_message")
);

const options = context.signupLoginOptions("1028295100269838", "https://beshareaisolution.com/whatsapp/ruang.html");
check(
  "v4 login omits an empty feature type and keeps the session version",
  options.config_id === "1028295100269838"
    && options.response_type === "code"
    && options.override_default_response_type === true
    && options.fallback_redirect_uri === "https://beshareaisolution.com/whatsapp/ruang.html"
    && options.extras.setup && Object.keys(options.extras.setup).length === 0
    && options.extras.sessionInfoVersion === "3"
    && !Object.hasOwn(options.extras, "featureType")
    && !Object.hasOwn(options, "featureType")
);
const coexistence = context.coexistenceLoginOptions("1028295100269838", "https://beshareaisolution.com/whatsapp/ruang.html");
check(
  "coexistence launch adds only the business app feature type",
  coexistence.config_id === options.config_id
    && coexistence.response_type === "code"
    && coexistence.override_default_response_type === true
    && coexistence.fallback_redirect_uri === options.fallback_redirect_uri
    && coexistence.extras.setup && Object.keys(coexistence.extras.setup).length === 0
    && coexistence.extras.sessionInfoVersion === "3"
    && coexistence.extras.featureType === "whatsapp_business_app_onboarding"
    && !Object.hasOwn(options.extras, "featureType")
);
context.location = { origin: "https://beshareaisolution.com", pathname: "/whatsapp/ruang.html" };
check("redirect target drops the hash and query", context.signupPageRedirect() === "https://beshareaisolution.com/whatsapp/ruang.html");
context.window = { FB: { __buffer: { calls: [] }, login() {} } };
check("sdk loader stub is not treated as ready", context.facebookSdkReady() === false);
context.window = { FB: { login() {} } };
check("loaded sdk is ready", context.facebookSdkReady() === true);
check("meta status stays a short word", context.metaStatus({ status: "unknown", authResponse: { code: "secret-code" } }) === "unknown");
check("meta status drops unexpected text", context.metaStatus({ status: "code=secret" }) === "");

const click = source.slice(source.indexOf("function beginSignup"), source.indexOf("function exchangeSignupCode"));
const loginAt = click.indexOf("window.FB.login");
const fetchAt = click.indexOf('callApi("sambung"');
check("embedded signup opens before the session request", loginAt > 0 && fetchAt > loginAt);
check("facebook messages stay on a facebook host", context.facebookHost("https://www.facebook.com") && context.facebookHost("https://web.facebook.com") && context.facebookHost("https://business.facebook.com") && context.facebookHost("https://m.facebook.com"));
check("foreign origins are ignored", !context.facebookHost("https://facebook.com.evil.com") && !context.facebookHost("https://example.com"));
const payload = context.signupPayload("https://business.facebook.com", JSON.stringify({
  type: "WA_EMBEDDED_SIGNUP",
  event: "FINISH",
  data: { waba_id: "1104940391945821", phone_number_id: "100000000000202" }
}));
const finish = context.signupEventFields(payload);
check(
  "string postMessage from business facebook keeps finish ids",
  payload && finish.event === "FINISH" && finish.waba_id === "1104940391945821" && finish.phone_number_id === "100000000000202"
);
check("unrelated postMessage is ignored", context.signupPayload("https://www.facebook.com", JSON.stringify({ type: "other" })) === null);
const returned = context.signupQueryResult("?code=AQABCDE123_~.-ok&error_description=secret");
check("redirect keeps a usable code and drops the description", returned.code === "AQABCDE123_~.-ok" && returned.error === "");
check("oauth error is a short reason", context.signupQueryResult("?error_reason=user_denied").error === "user_denied");
check(
  "saved connection is the only success note",
  context.signupResultNote({ ok: true, connection: { linked: true, note: "lama" } }) === "Sambungan WhatsApp disimpan."
);
check(
  "incomplete result is not called success",
  context.signupResultNote({ ok: true, connection: { linked: false, note: "" } }) === "Hasil Meta belum lengkap. Sambungan tidak disimpan."
    && context.signupResultNote({ ok: true, connection: { linked: false, note: "Kod ditukar. Menunggu pengesahan nombor daripada Meta." } }) === "Kod ditukar. Menunggu pengesahan nombor daripada Meta."
);
check(
  "in-app browser failure names Safari",
  context.signupClientHint("Mozilla FBAN") === " Buka halaman ini dalam Safari. Pelayar dalam aplikasi sering menggugurkan hasil Meta."
    && context.signupClientHint("Mozilla/5.0 (iPhone; Safari)") === ""
);
check(
  "signup source keeps both results across a reload and does not claim a false success",
  source.includes('const SIGNUP_RECORD = "beshare-os-signup"')
    && source.includes("visibilitychange")
    && source.includes("history.replaceState")
    && source.includes("rememberSignupCode")
    && source.includes("rememberSignupEvent")
    && !source.includes("Peristiwa Meta direkod")
    && !source.includes("Keadaan sambungan dikemas kini")
);
const standardLaunch = source.slice(source.indexOf("function openEmbeddedSignup"), source.indexOf("function openCoexistenceSignup"));
const appLaunch = source.slice(source.indexOf("function openCoexistenceSignup"), source.indexOf("function beginSignup"));
check(
  "standard signup keeps its own launch options",
  standardLaunch.includes("signupLoginOptions(") && !standardLaunch.includes("coexistenceLoginOptions") && !standardLaunch.includes("featureType")
);
check(
  "business app signup uses the coexistence launch",
  appLaunch.includes("coexistenceLoginOptions(") && !appLaunch.includes("signupLoginOptions(")
);
check(
  "signup source sends the named coexistence feature and no empty feature type",
  source.includes('featureType: "whatsapp_business_app_onboarding"')
    && !source.includes('featureType: ""')
    && !source.includes("featureType: ''")
    && source.includes('data-act="connect"')
    && source.includes(">Buka tetingkap Meta<")
    && source.includes('data-act="connect-app"')
    && source.includes(">Sambung WhatsApp Business App<")
);

const sent = { codes: [], events: [] };
const store = new Map();
const storageContext = createContext({
  URL,
  URLSearchParams,
  sessionStorage: {
    getItem: (key) => (store.has(key) ? store.get(key) : null),
    setItem: (key, value) => store.set(key, value),
    removeItem: (key) => store.delete(key)
  },
  __sent: sent
});
const storageStart = source.indexOf("const SIGNUP_RECORD");
const storageEnd = source.indexOf("function beginSignup");
const signupCodeSource = source.slice(source.indexOf("function signupCode"), source.indexOf("function signupQueryResult"));
runInContext(`let signupState = "";
let signupQueue = null;
${signupCodeSource}
${source.slice(storageStart, storageEnd)}
function exchangeSignupCode(code) { __sent.codes.push(code); }
function sendSignupEvent(fields) { __sent.events.push(fields.event); }
`, storageContext);
const state = "a".repeat(64);
storageContext.rememberSignupEvent({ event: "FINISH", waba_id: "123", phone_number_id: "456" });
storageContext.rememberSignupCode("AQABCDE123_~.-ok");
storageContext.flushSignup();
check("finish and code wait until the server state exists", sent.codes.length === 0 && sent.events.length === 0);
storageContext.rememberSignupState(state);
check(
  "finish then code is sent once the server state arrives",
  sent.codes.length === 1 && sent.codes[0] === "AQABCDE123_~.-ok" && sent.events.length === 1 && sent.events[0] === "FINISH"
);
sent.codes.length = 0;
sent.events.length = 0;
store.clear();
storageContext.rememberSignupState(state);
storageContext.rememberSignupCode("AQZYXWV987_~.-ok");
check("code after the server state is exchanged without a second copy", sent.codes.length === 1 && sent.codes[0] === "AQZYXWV987_~.-ok" && sent.events.length === 0);
storageContext.rememberSignupEvent({ event: "FINISH", waba_id: "123", phone_number_id: "456" });
check("a later finish is still delivered on the same session", sent.events.length === 1 && sent.events[0] === "FINISH" && sent.codes.length === 1);

process.exit(failures === 0 ? 0 : 1);

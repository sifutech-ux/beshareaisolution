import { readFileSync } from "node:fs";
import { createContext, runInContext } from "node:vm";

const source = readFileSync(new URL("../../whatsapp/ruang.js", import.meta.url), "utf8");
const start = source.indexOf("function facebookSdkReady");
const end = source.indexOf("function openEmbeddedSignup");
if (start < 0 || end < start) {
  console.log("FAIL signup helpers are present");
  process.exit(1);
}
const context = createContext({});
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
  options.response_type === "code"
    && options.override_default_response_type === true
    && options.fallback_redirect_uri === "https://beshareaisolution.com/whatsapp/ruang.html"
    && options.extras.setup && Object.keys(options.extras.setup).length === 0
    && options.extras.sessionInfoVersion === "3"
    && !Object.hasOwn(options.extras, "featureType")
    && !Object.hasOwn(options, "featureType")
);
context.location = { origin: "https://beshareaisolution.com", pathname: "/whatsapp/ruang.html" };
check("redirect target drops the hash and query", context.signupPageRedirect() === "https://beshareaisolution.com/whatsapp/ruang.html");
context.window = { FB: { __buffer: { calls: [] }, login() {} } };
check("sdk loader stub is not treated as ready", context.facebookSdkReady() === false);
context.window = { FB: { login() {} } };
check("loaded sdk is ready", context.facebookSdkReady() === true);
check("meta status stays a short word", context.metaStatus({ status: "unknown", authResponse: { code: "secret-code" } }) === "unknown");
check("meta status drops unexpected text", context.metaStatus({ status: "code=secret" }) === "");

const click = source.slice(source.indexOf("function openEmbeddedSignup"), source.indexOf("function settleSignup"));
const loginAt = click.indexOf("window.FB.login");
const fetchAt = click.indexOf('callApi("sambung"');
check("embedded signup opens before the session request", loginAt > 0 && fetchAt > loginAt);
check("signup source does not send an empty feature type", !source.includes("featureType:"));

process.exit(failures === 0 ? 0 : 1);

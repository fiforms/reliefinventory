"""A minimal IPP (RFC 8010/8011) codec — just enough to talk to the CUPS
scheduler on this device over HTTP, with no extra dependencies.

Why not `lp`/`lpstat` or pycups: pycups isn't in the platform's requirements
(and needs the CUPS dev headers to build), and `lpstat` text can't tell a
job that *completed* from one that was *aborted*, which the print server has
to know before it tells the website "printed". IPP answers both exactly
(job-state, job-state-reasons, printer-state-reasons), and the same few
hundred bytes of protocol work against any IPP printer, not just CUPS.

Only the value types the print server actually reads and writes are
decoded into friendly Python values; anything else comes back as raw bytes
rather than raising, so an unexpected attribute can never break a poll.
"""
import struct
from dataclasses import dataclass, field

# Operations
PRINT_JOB = 0x0002
CANCEL_JOB = 0x0008
GET_JOB_ATTRIBUTES = 0x0009
GET_JOBS = 0x000A
GET_PRINTER_ATTRIBUTES = 0x000B
CUPS_GET_PRINTERS = 0x4002

# Attribute-group delimiter tags
OPERATION_ATTRIBUTES = 0x01
JOB_ATTRIBUTES = 0x02
END_OF_ATTRIBUTES = 0x03
PRINTER_ATTRIBUTES = 0x04
UNSUPPORTED_ATTRIBUTES = 0x05

# Value tags
_OUT_OF_BAND = {0x10, 0x12, 0x13, 0x15}  # unsupported / unknown / no-value / not-settable
INTEGER = 0x21
BOOLEAN = 0x22
ENUM = 0x23
OCTET_STRING = 0x30
BEG_COLLECTION = 0x34
END_COLLECTION = 0x37
MEMBER_ATTR_NAME = 0x4A
TEXT = 0x41
NAME = 0x42
KEYWORD = 0x44
URI = 0x45
CHARSET = 0x47
NATURAL_LANGUAGE = 0x48
MIME_MEDIA_TYPE = 0x49

_STRING_TAGS = {TEXT, NAME, KEYWORD, URI, 0x46, CHARSET, NATURAL_LANGUAGE, MIME_MEDIA_TYPE, 0x35, 0x36}

# printer-state (RFC 8011 5.4.11)
PRINTER_IDLE = 3
PRINTER_PROCESSING = 4
PRINTER_STOPPED = 5

# job-state (RFC 8011 5.3.7)
JOB_PENDING = 3
JOB_HELD = 4
JOB_PROCESSING = 5
JOB_PROCESSING_STOPPED = 6
JOB_CANCELED = 7
JOB_ABORTED = 8
JOB_COMPLETED = 9


class IppError(Exception):
    """The IPP response was malformed, or the operation came back as a failure."""

    def __init__(self, message: str, status: int | None = None):
        super().__init__(message)
        self.status = status


@dataclass
class IppValue:
    """A value to encode: `tag` picks the wire type (see the constants above)."""

    tag: int
    value: object


def keyword(value: str) -> IppValue:
    return IppValue(KEYWORD, value)


def name(value: str) -> IppValue:
    return IppValue(NAME, value)


def uri(value: str) -> IppValue:
    return IppValue(URI, value)


def integer(value: int) -> IppValue:
    return IppValue(INTEGER, value)


def mime(value: str) -> IppValue:
    return IppValue(MIME_MEDIA_TYPE, value)


def _encode_value(item: IppValue) -> bytes:
    if item.tag in (INTEGER, ENUM):
        return struct.pack(">i", int(item.value))
    if item.tag == BOOLEAN:
        return b"\x01" if item.value else b"\x00"
    if isinstance(item.value, bytes):
        return item.value
    return str(item.value).encode("utf-8")


def encode_request(operation: int, request_id: int, operation_attributes: dict, job_attributes: dict | None = None) -> bytes:
    """Builds an IPP/2.0 request (without any document data — append that
    after this for Print-Job). Attribute values may be IppValue, or a list of
    IppValue for a multi-valued attribute. `attributes-charset` and
    `attributes-natural-language` must be first in the operation group, which
    insertion order of the dict preserves.
    """
    out = bytearray(struct.pack(">BBHI", 2, 0, operation, request_id))
    for group_tag, attributes in ((OPERATION_ATTRIBUTES, operation_attributes), (JOB_ATTRIBUTES, job_attributes)):
        if not attributes:
            continue
        out.append(group_tag)
        for attr_name, values in attributes.items():
            values = values if isinstance(values, list) else [values]
            for index, item in enumerate(values):
                encoded_name = attr_name.encode("utf-8") if index == 0 else b""
                encoded_value = _encode_value(item)
                out += struct.pack(">BH", item.tag, len(encoded_name)) + encoded_name
                out += struct.pack(">H", len(encoded_value)) + encoded_value
    out.append(END_OF_ATTRIBUTES)
    return bytes(out)


@dataclass
class IppResponse:
    status: int
    request_id: int
    # (group tag, {attribute name: value | [values]}) in wire order. A printer
    # listing has one PRINTER_ATTRIBUTES group per printer.
    groups: list = field(default_factory=list)

    @property
    def ok(self) -> bool:
        return self.status < 0x0100  # successful-ok and the successful-ok-* variants

    def group_attributes(self, tag: int) -> list[dict]:
        return [attributes for group_tag, attributes in self.groups if group_tag == tag]

    def first(self, tag: int) -> dict:
        found = self.group_attributes(tag)
        return found[0] if found else {}


def _decode_value(tag: int, raw: bytes):
    if tag in (INTEGER, ENUM) and len(raw) == 4:
        return struct.unpack(">i", raw)[0]
    if tag == BOOLEAN and len(raw) == 1:
        return raw != b"\x00"
    if tag in _STRING_TAGS:
        return raw.decode("utf-8", errors="replace")
    if tag in _OUT_OF_BAND:
        return None
    return raw


def decode_response(data: bytes) -> IppResponse:
    """Parses an IPP response (anything after the end-of-attributes tag, such
    as a document, is ignored). Raises IppError if it is truncated."""
    try:
        return _decode_response(data)
    except (struct.error, IndexError) as exc:
        raise IppError(f"Truncated or malformed IPP response: {exc}") from exc


def _decode_response(data: bytes) -> IppResponse:
    if len(data) < 8:
        raise IppError("IPP response is too short")
    _major, _minor, status, request_id = struct.unpack_from(">BBHI", data, 0)
    response = IppResponse(status=status, request_id=request_id)
    position = 8
    current: dict | None = None
    last_name: str | None = None

    while True:
        tag = data[position]
        position += 1
        if tag == END_OF_ATTRIBUTES:
            return response
        if tag <= 0x05:  # a new attribute group (operation, job, printer, unsupported, ...)
            current = {}
            response.groups.append((tag, current))
            last_name = None
            continue
        if current is None:
            raise IppError("IPP attribute outside a group")
        last_name, position = _read_attribute(data, position, tag, current, last_name)


def _read_attribute(data: bytes, position: int, tag: int, target: dict, last_name: str | None):
    (name_length,) = struct.unpack_from(">H", data, position)
    position += 2
    attr_name = data[position:position + name_length].decode("utf-8", errors="replace")
    position += name_length
    (value_length,) = struct.unpack_from(">H", data, position)
    position += 2
    raw = data[position:position + value_length]
    position += value_length

    if tag == BEG_COLLECTION:
        value, position = _read_collection(data, position)
    else:
        value = _decode_value(tag, raw)

    # An empty name means "another value of the previous attribute".
    key = attr_name or last_name
    if key is None:
        raise IppError("IPP additional value with no attribute")
    if key in target and (attr_name == ""):
        existing = target[key]
        target[key] = (existing if isinstance(existing, list) else [existing]) + [value]
    else:
        target[key] = value
    return key, position


def _read_collection(data: bytes, position: int):
    """Reads collection members up to the matching end-collection tag. The
    print server never asks for collection attributes, but a response can
    carry them, so they are walked (not skipped by guess) and returned as a dict."""
    members: dict = {}
    member_name: str | None = None
    while True:
        tag = data[position]
        position += 1
        (name_length,) = struct.unpack_from(">H", data, position)
        position += 2 + name_length
        (value_length,) = struct.unpack_from(">H", data, position)
        position += 2
        raw = data[position:position + value_length]
        position += value_length
        if tag == END_COLLECTION:
            return members, position
        if tag == MEMBER_ATTR_NAME:
            member_name = raw.decode("utf-8", errors="replace")
            continue
        if tag == BEG_COLLECTION:
            value, position = _read_collection(data, position)
        else:
            value = _decode_value(tag, raw)
        if member_name is not None:
            members[member_name] = value

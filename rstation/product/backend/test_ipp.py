import struct

import pytest

from . import ipp
from .ipp_fixtures import JOB_COMPLETED as REAL_JOB_COMPLETED
from .ipp_fixtures import JOB_NOT_FOUND, PRINT_JOB, PRINTER_IDLE, PRINTER_UNREACHABLE


def test_decodes_a_real_cups_printer_attributes_response():
    response = ipp.decode_response(PRINTER_IDLE)

    assert response.ok and response.status == 0
    printer = response.first(ipp.PRINTER_ATTRIBUTES)
    assert printer["printer-name"] == "ri-test-ok"
    assert printer["printer-state"] == ipp.PRINTER_IDLE
    assert printer["printer-is-accepting-jobs"] is True
    assert printer["printer-state-reasons"] == "none"


def test_decodes_a_real_cups_print_job_response():
    job = ipp.decode_response(PRINT_JOB).first(ipp.JOB_ATTRIBUTES)

    assert isinstance(job["job-id"], int) and job["job-id"] > 0
    assert "job-state" in job


def test_decodes_a_real_completed_job_and_its_progress():
    job = ipp.decode_response(REAL_JOB_COMPLETED).first(ipp.JOB_ATTRIBUTES)

    assert job["job-state"] == ipp.JOB_COMPLETED
    assert job["job-state-reasons"] == "job-completed-successfully"
    assert job["job-impressions-completed"] == 1


def test_a_real_not_found_answer_is_an_unsuccessful_status_with_a_message():
    response = ipp.decode_response(JOB_NOT_FOUND)

    assert not response.ok and response.status == 0x0406
    assert "status-message" in response.first(ipp.OPERATION_ATTRIBUTES)


def test_decodes_the_real_message_cups_gives_for_an_unreachable_printer():
    printer = ipp.decode_response(PRINTER_UNREACHABLE).first(ipp.PRINTER_ATTRIBUTES)

    assert printer["printer-state"] == ipp.PRINTER_PROCESSING
    assert printer["printer-state-message"] == "The printer may not exist or is unavailable at this time."


def test_encoded_requests_round_trip_including_multi_valued_attributes():
    data = ipp.encode_request(
        ipp.GET_PRINTER_ATTRIBUTES, 7,
        {"attributes-charset": ipp.IppValue(ipp.CHARSET, "utf-8"),
         "requested-attributes": [ipp.keyword("printer-state"), ipp.keyword("printer-name")],
         "my-jobs": ipp.IppValue(ipp.BOOLEAN, True)},
        {"copies": ipp.integer(3)},
    )

    # A request has the same layout as a response (operation id where the status goes).
    decoded = ipp.decode_response(data)

    assert decoded.status == ipp.GET_PRINTER_ATTRIBUTES and decoded.request_id == 7
    operation = decoded.first(ipp.OPERATION_ATTRIBUTES)
    assert operation["requested-attributes"] == ["printer-state", "printer-name"]
    assert operation["my-jobs"] is True
    assert decoded.first(ipp.JOB_ATTRIBUTES)["copies"] == 3


def test_utf8_names_survive_encoding():
    data = ipp.encode_request(ipp.PRINT_JOB, 1, {"job-name": ipp.name("Étiquette 日本")})

    assert ipp.decode_response(data).first(ipp.OPERATION_ATTRIBUTES)["job-name"] == "Étiquette 日本"


def _attribute(tag: int, name: str, value: bytes) -> bytes:
    return struct.pack(">BH", tag, len(name)) + name.encode() + struct.pack(">H", len(value)) + value


def test_collections_are_walked_not_misread():
    collection = (
        _attribute(ipp.BEG_COLLECTION, "media-col", b"")
        + _attribute(ipp.MEMBER_ATTR_NAME, "", b"media-size")
        + _attribute(ipp.BEG_COLLECTION, "", b"")
        + _attribute(ipp.MEMBER_ATTR_NAME, "", b"x-dimension")
        + _attribute(ipp.INTEGER, "", struct.pack(">i", 10160))
        + _attribute(ipp.END_COLLECTION, "", b"")
        + _attribute(ipp.END_COLLECTION, "", b"")
    )
    data = struct.pack(">BBHI", 2, 0, 0, 1) + bytes([ipp.PRINTER_ATTRIBUTES]) + collection \
        + _attribute(ipp.INTEGER, "printer-state", struct.pack(">i", 3)) + bytes([ipp.END_OF_ATTRIBUTES])

    printer = ipp.decode_response(data).first(ipp.PRINTER_ATTRIBUTES)

    assert printer["media-col"] == {"media-size": {"x-dimension": 10160}}
    assert printer["printer-state"] == 3  # decoding carried on correctly after the collection


def test_unknown_value_types_come_back_as_bytes_instead_of_raising():
    data = struct.pack(">BBHI", 2, 0, 0, 1) + bytes([ipp.PRINTER_ATTRIBUTES]) \
        + _attribute(0x31, "date-time", b"\x07\xea\x0a\x12\x00\x00\x00\x00+\x00\x00") + bytes([ipp.END_OF_ATTRIBUTES])

    assert isinstance(ipp.decode_response(data).first(ipp.PRINTER_ATTRIBUTES)["date-time"], bytes)


@pytest.mark.parametrize("data", [b"", b"\x02\x00", PRINTER_IDLE[:30], PRINTER_IDLE[:-1]])
def test_truncated_responses_raise_a_clear_error(data):
    with pytest.raises(ipp.IppError):
        ipp.decode_response(data)

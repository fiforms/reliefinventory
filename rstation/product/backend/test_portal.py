from products.portal import product, ping


def test_product_extends_the_core_contract():
    assert product.api_base == "/api/devices"
    assert product.heartbeat_payload() == {"portal_note": "hello from the example product"}
    assert product.status_fields()["portal"]["mode"] == "remote"
    assert ping() == {"ok": True}

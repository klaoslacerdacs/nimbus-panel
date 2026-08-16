provider "oci" {
  region = var.region
  # ponytail: auth omitted — OCI Resource Manager injects Instance Principal auth automatically
}

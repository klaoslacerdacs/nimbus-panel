variable "region" {
  type        = string
  description = "OCI region identifier (e.g. sa-saopaulo-1)"
}

provider "oci" {
  region = var.region
  # ponytail: auth omitted — OCI Resource Manager injects Instance Principal auth automatically
}

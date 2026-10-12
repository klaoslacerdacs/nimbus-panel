variable "region" {
  type        = string
  description = "OCI region identifier (e.g. sa-saopaulo-1)"
}

variable "create_compartment" {
  type    = bool
  default = false
}

variable "compartment_ocid" {
  type    = string
  default = ""
  # ponytail: cross-variable validation removed — OCI RM Terraform < 1.9 doesn't support it
}

variable "compartment_name" {
  type    = string
  default = ""
}

variable "tenancy_ocid" {
  type        = string
  description = "Tenancy OCID — used as fallback parent when creating a new compartment"
}

variable "parent_compartment_ocid" {
  type    = string
  default = ""
}

variable "create_network" {
  type    = bool
  default = false
}

variable "vcn_ocid" {
  type    = string
  default = ""
}

variable "subnet_ocid" {
  type    = string
  default = ""
}

variable "vcn_display_name" {
  type    = string
  default = ""
}

variable "vcn_dns_label" {
  type    = string
  default = "nimbus"
  validation {
    condition     = can(regex("^[a-z][a-z0-9]{0,14}$", var.vcn_dns_label))
    error_message = "vcn_dns_label deve ter no máximo 15 caracteres alfanuméricos, iniciando com letra."
  }
}

variable "vcn_cidr" {
  type    = string
  default = "10.0.0.0/16"
  validation {
    condition     = can(cidrnetmask(var.vcn_cidr))
    error_message = "vcn_cidr deve ser um CIDR válido (ex: 10.0.0.0/16)."
  }
}

variable "subnet_cidr" {
  type    = string
  default = ""
}

variable "subnet_display_name" {
  type    = string
  default = ""
}

variable "subnet_dns_label" {
  type    = string
  default = "public"
  validation {
    condition     = can(regex("^[a-z][a-z0-9]{0,14}$", var.subnet_dns_label))
    error_message = "subnet_dns_label inválido."
  }
}

variable "instance_display_name" {
  type        = string
  description = "Display name for the compute instance"
}

variable "availability_domain" {
  type        = string
  description = "Availability domain name"
}

variable "shape" {
  type    = string
  default = "VM.Standard.A1.Flex"
}

variable "ocpus" {
  type    = number
  default = 1
}

variable "memory_in_gbs" {
  type    = number
  default = 6
}

variable "image_id" {
  type        = string
  description = "Compute image OCID"
}

variable "ssh_authorized_keys" {
  type      = string
  sensitive = true
}

variable "boot_volume_size_in_gbs" {
  type    = number
  default = 50
}

variable "project_tag" {
  type = string
}

variable "environment_tag" {
  type = string
}

variable "nimbus_version" {
  type    = string
  default = "latest"
}

variable "existing_nsg_ocid" {
  type        = string
  default     = ""
  description = "OCID of an existing NSG to attach when not creating a new network. Leave empty for no NSG."
}

variable "ingress_rules_json" {
  type        = string
  default     = "[]"
  description = "JSON list of NSG ingress rules managed by Nimbus Panel: [{protocol, port_min, port_max, source, description}]. Resource Manager variables are strings, hence JSON."
}

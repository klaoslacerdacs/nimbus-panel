terraform {
  required_providers {
    oci = {
      source  = "oracle/oci"
      version = "~> 6.0"
    }
  }
}

locals {
  compartment_id = var.create_compartment ? oci_identity_compartment.new[0].id : var.compartment_ocid
  vcn_id         = var.create_network ? oci_core_vcn.new[0].id : var.vcn_ocid
  subnet_id      = var.create_network ? oci_core_subnet.new[0].id : var.subnet_ocid

  resolved_subnet_cidr = var.subnet_cidr != "" ? var.subnet_cidr : cidrsubnet(var.vcn_cidr, 8, 0)

  resolved_vcn_dns_label = var.vcn_dns_label != "" ? var.vcn_dns_label : substr(
    replace(lower(var.vcn_display_name), "/[^a-z0-9]/", ""), 0, 15
  )

  resolved_vcn_display_name    = var.vcn_display_name != "" ? var.vcn_display_name : "nimbus-vcn"
  resolved_subnet_display_name = var.subnet_display_name != "" ? var.subnet_display_name : "${local.resolved_vcn_display_name}-public"

  tags = {
    "nimbus:managed_by"  = "terraform"
    "nimbus:project"     = var.project_tag
    "nimbus:environment" = var.environment_tag
    "nimbus:version"     = var.nimbus_version
  }

  # Every instance gets an NSG (created here unless one is supplied) so firewall rules
  # from the wizard and dashboard work on existing VCNs too.
  nsg_id = var.existing_nsg_ocid != "" ? var.existing_nsg_ocid : oci_core_network_security_group.new[0].id
}

resource "oci_identity_compartment" "new" {
  count = var.create_compartment ? 1 : 0

  compartment_id = var.parent_compartment_ocid != "" ? var.parent_compartment_ocid : var.tenancy_ocid
  name           = var.compartment_name
  description    = "Managed by Nimbus Panel"
  freeform_tags  = local.tags

  lifecycle {
    ignore_changes = [defined_tags]
  }
}

resource "oci_core_vcn" "new" {
  count = var.create_network ? 1 : 0

  compartment_id = local.compartment_id
  display_name   = local.resolved_vcn_display_name
  cidr_blocks    = [var.vcn_cidr]
  dns_label      = local.resolved_vcn_dns_label
  freeform_tags  = local.tags
}

resource "oci_core_internet_gateway" "new" {
  count = var.create_network ? 1 : 0

  compartment_id = local.compartment_id
  vcn_id         = local.vcn_id
  display_name   = "${local.resolved_vcn_display_name}-igw"
  enabled        = true
  freeform_tags  = local.tags
}

resource "oci_core_route_table" "new" {
  count = var.create_network ? 1 : 0

  compartment_id = local.compartment_id
  vcn_id         = local.vcn_id
  display_name   = "${local.resolved_vcn_display_name}-rt"
  freeform_tags  = local.tags

  route_rules {
    destination       = "0.0.0.0/0"
    destination_type  = "CIDR_BLOCK"
    network_entity_id = oci_core_internet_gateway.new[0].id
  }
}

resource "oci_core_security_list" "new" {
  count = var.create_network ? 1 : 0

  compartment_id = local.compartment_id
  vcn_id         = local.vcn_id
  display_name   = "${local.resolved_vcn_display_name}-sl"
  freeform_tags  = local.tags

  # ponytail: Security List at subnet level; use NSG for per-instance rules
  ingress_security_rules {
    protocol = "6"
    source   = "0.0.0.0/0"
    tcp_options {
      min = 22
      max = 22
    }
  }

  ingress_security_rules {
    protocol = "1"
    source   = "0.0.0.0/0"
    icmp_options {
      type = 3
      code = 4
    }
  }

  egress_security_rules {
    protocol    = "all"
    destination = "0.0.0.0/0"
  }
}

resource "oci_core_subnet" "new" {
  count = var.create_network ? 1 : 0

  compartment_id             = local.compartment_id
  vcn_id                     = local.vcn_id
  display_name               = local.resolved_subnet_display_name
  cidr_block                 = local.resolved_subnet_cidr
  dns_label                  = var.subnet_dns_label
  prohibit_public_ip_on_vnic = false
  route_table_id             = oci_core_route_table.new[0].id
  security_list_ids          = [oci_core_security_list.new[0].id]
  freeform_tags              = local.tags
}

resource "oci_core_network_security_group" "new" {
  count = var.existing_nsg_ocid == "" ? 1 : 0

  compartment_id = local.compartment_id
  vcn_id         = local.vcn_id
  display_name   = "${var.instance_display_name}-nsg"
  freeform_tags  = local.tags
}

resource "oci_core_instance" "server" {
  compartment_id      = local.compartment_id
  availability_domain = var.availability_domain
  display_name        = var.instance_display_name
  shape               = var.shape

  dynamic "shape_config" {
    for_each = can(regex("Flex", var.shape)) ? [1] : []
    content {
      ocpus         = var.ocpus
      memory_in_gbs = var.memory_in_gbs
    }
  }

  source_details {
    source_type             = "image"
    source_id               = var.image_id
    boot_volume_size_in_gbs = var.boot_volume_size_in_gbs
  }

  create_vnic_details {
    subnet_id        = local.subnet_id
    assign_public_ip = true
    display_name     = "${var.instance_display_name}-vnic"
    nsg_ids          = [local.nsg_id]
  }

  metadata = {
    ssh_authorized_keys = var.ssh_authorized_keys
  }

  freeform_tags = local.tags

  lifecycle {
    ignore_changes = [metadata["user_data"]]
  }
}

variable "compartment_ocid" {
  type        = string
  description = "Compartment where all resources will be created"
}

variable "subnet_ocid" {
  type        = string
  description = "Public subnet OCID for the instance"
}

variable "image_id" {
  type        = string
  description = "Compute image OCID"
}

variable "availability_domain" {
  type        = string
  description = "Availability domain name (e.g. kWOu:SA-SAOPAULO-1-AD-1)"
}

variable "instance_name" {
  type        = string
  description = "Display name for the compute instance"
}

variable "ssh_authorized_keys" {
  type        = string
  description = "SSH public key(s) authorized to access the instance"
  sensitive   = true
}

variable "shape" {
  type        = string
  default     = "VM.Standard.A1.Flex"
  description = "Compute shape"
}

variable "ocpus" {
  type        = number
  default     = 1
  description = "Number of OCPUs (Flex shapes only)"
}

variable "memory_in_gbs" {
  type        = number
  default     = 6
  description = "Memory in GB (Flex shapes only)"
}

variable "boot_volume_size_in_gbs" {
  type        = number
  default     = 50
  description = "Boot volume size in GB"
}

variable "project_tag" {
  type        = string
  description = "Project name for tagging"
}

variable "environment_tag" {
  type        = string
  description = "Environment name for tagging (e.g. production, staging)"
}

variable "nimbus_version" {
  type        = string
  default     = "latest"
  description = "Nimbus Panel version for tagging"
}
